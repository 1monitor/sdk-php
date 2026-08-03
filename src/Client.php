<?php

declare(strict_types=1);

namespace OneMonitor\Sdk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use OneMonitor\Sdk\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Sends pings to 1Monitor.
 *
 * Delivery never throws: every ping method returns `true` when 1Monitor
 * accepted the ping and `false` otherwise. Only misconfiguration throws.
 */
final class Client
{
    public const VERSION = '0.1.0';

    public const DEFAULT_BASE_URL = 'https://ping.1monitor.io';
    public const DEFAULT_TIMEOUT = 5.0;
    public const DEFAULT_RETRIES = 2;

    /** Seconds to wait before retry N; the last entry repeats for further retries. */
    private const BACKOFF_SECONDS = [0.5, 1.0];

    private readonly string $baseUrl;

    private readonly LoggerInterface $logger;

    private readonly ClientInterface $httpClient;

    /**
     * @param float $timeout Overall deadline for one ping across all attempts, in seconds.
     * @param int   $retries Extra attempts after the first one fails. 0 disables retrying.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $retries = self::DEFAULT_RETRIES,
        ?LoggerInterface $logger = null,
        ?ClientInterface $httpClient = null,
    ) {
        if ($timeout <= 0) {
            throw new InvalidArgumentException(sprintf('Timeout must be greater than 0, %s given.', $timeout));
        }

        if ($retries < 0) {
            throw new InvalidArgumentException(sprintf('Retries must not be negative, %d given.', $retries));
        }

        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->logger = $logger ?? new NullLogger();
        $this->httpClient = $httpClient ?? new GuzzleClient();
    }

    /**
     * Records a liveness ping — the job is alive.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function ping(string $token): bool
    {
        return $this->send($token, PingState::Ping);
    }

    /**
     * Opens a run — the job has started.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function pingStart(string $token): bool
    {
        return $this->send($token, PingState::Start);
    }

    /**
     * Closes the open run as successful.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function pingSuccess(string $token): bool
    {
        return $this->send($token, PingState::Success);
    }

    /**
     * Closes the open run as failed.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function pingFail(string $token): bool
    {
        return $this->send($token, PingState::Fail);
    }

    private function send(string $token, PingState $state): bool
    {
        if (trim($token) === '') {
            throw new InvalidArgumentException('Ping token must not be empty.');
        }

        $url = $this->baseUrl . '/ping/' . rawurlencode($token) . $state->pathSuffix();

        // The timeout is a budget for time spent on the wire, spread across all
        // attempts, so that retrying cannot multiply how long the monitored job
        // is held up. Backoff sleeps are deliberately outside the budget.
        $budget = $this->timeout;
        $maxAttempts = $this->retries + 1;

        $attempt = 0;
        $status = null;
        $error = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            [$status, $error, $elapsed] = $this->attempt($url, $budget);
            $budget -= $elapsed;

            if ($status !== null && $status >= 200 && $status < 300) {
                return true;
            }

            $retryable = $status === null || $status >= 500;
            if (!$retryable || $attempt >= $maxAttempts || $budget <= 0) {
                break;
            }

            $this->sleepBeforeRetry($attempt);
        }

        $this->logger->error('1Monitor ping failed.', [
            'state' => $state->value,
            'baseUrl' => $this->baseUrl,
            'attempts' => $attempt,
            'status' => $status,
            'exception' => $error,
        ]);

        return false;
    }

    /**
     * One HTTP attempt.
     *
     * @return array{0: int|null, 1: Throwable|null, 2: float} status (null on transport
     *     failure), the error if any, and seconds spent
     */
    private function attempt(string $url, float $budget): array
    {
        $startedAt = microtime(true);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => $budget,
                'connect_timeout' => $budget,
                'http_errors' => false,
                'headers' => [
                    'User-Agent' => '1monitor-sdk-php/' . self::VERSION,
                ],
            ]);

            return [$response->getStatusCode(), null, microtime(true) - $startedAt];
        } catch (RequestException $e) {
            return [$e->getResponse()?->getStatusCode(), $e, microtime(true) - $startedAt];
        } catch (Throwable $e) {
            return [null, $e, microtime(true) - $startedAt];
        }
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        $index = min($attempt - 1, count(self::BACKOFF_SECONDS) - 1);

        usleep((int) round(self::BACKOFF_SECONDS[$index] * 1_000_000));
    }

    /** @throws InvalidArgumentException */
    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $normalized = rtrim(trim($baseUrl), '/');
        $scheme = $normalized === '' ? null : parse_url($normalized, PHP_URL_SCHEME);

        if (
            $normalized === ''
            || filter_var($normalized, FILTER_VALIDATE_URL) === false
            || !is_string($scheme)
            || !in_array(strtolower($scheme), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException(
                sprintf('Base URL must be a valid http(s) URL, "%s" given.', $baseUrl),
            );
        }

        return $normalized;
    }
}
