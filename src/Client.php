<?php

declare(strict_types=1);

namespace OneMonitor\Sdk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
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

    /**
     * The server keeps at most this many bytes of ping output; anything longer
     * is truncated client-side rather than sent just to be cut anyway.
     */
    public const MAX_OUTPUT_BYTES = 10 * 1024;

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
     * @param int|null    $exitCode The job's exit code; values outside 0–255 are ignored by the server.
     * @param string|null $output   The job's output, sent as the request body; an empty string sends none.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function ping(string $token, ?int $exitCode = null, ?string $output = null): bool
    {
        return $this->send($token, PingState::Ping, $exitCode, $output);
    }

    /**
     * Opens a run — the job has started.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function pingStart(string $token): bool
    {
        return $this->send($token, PingState::Start, null, null);
    }

    /**
     * Closes the open run as successful.
     *
     * @param int|null    $exitCode The job's exit code; values outside 0–255 are ignored by the server.
     * @param string|null $output   The job's output, sent as the request body; an empty string sends none.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function pingSuccess(string $token, ?int $exitCode = null, ?string $output = null): bool
    {
        return $this->send($token, PingState::Success, $exitCode, $output);
    }

    /**
     * Closes the open run as failed.
     *
     * @param int|null    $exitCode The job's exit code; values outside 0–255 are ignored by the server.
     * @param string|null $output   The job's output, sent as the request body; an empty string sends none.
     *
     * @throws InvalidArgumentException on an empty token
     */
    public function pingFail(string $token, ?int $exitCode = null, ?string $output = null): bool
    {
        return $this->send($token, PingState::Fail, $exitCode, $output);
    }

    private function send(string $token, PingState $state, ?int $exitCode, ?string $output): bool
    {
        if (trim($token) === '') {
            throw new InvalidArgumentException('Ping token must not be empty.');
        }

        $url = $this->baseUrl . '/ping/' . rawurlencode($token) . $state->pathSuffix();

        if ($exitCode !== null) {
            $url .= '?exit_code=' . $exitCode;
        }

        $body = $output === null || $output === '' ? null : self::truncateOutput($output);

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

            [$status, $error, $elapsed] = $this->attempt($url, $body, $budget);
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
    private function attempt(string $url, ?string $body, float $budget): array
    {
        $startedAt = microtime(true);

        $headers = [
            'User-Agent' => '1monitor-sdk-php/' . self::VERSION,
        ];

        $options = [
            'timeout' => $budget,
            'connect_timeout' => $budget,
            'http_errors' => false,
        ];

        if ($body !== null) {
            $headers['Content-Type'] = 'text/plain; charset=utf-8';
            $options['body'] = $body;
        }

        $options['headers'] = $headers;

        try {
            $response = $this->httpClient->request($body === null ? 'GET' : 'POST', $url, $options);

            return [$response->getStatusCode(), null, microtime(true) - $startedAt];
        } catch (RequestException $e) {
            // Guzzle 7 keeps the response on RequestException, Guzzle 8 only on
            // BadResponseException; the latter is the shape both majors share.
            $response = $e instanceof BadResponseException ? $e->getResponse() : null;

            return [$response?->getStatusCode(), $e, microtime(true) - $startedAt];
        } catch (Throwable $e) {
            return [null, $e, microtime(true) - $startedAt];
        }
    }

    private static function truncateOutput(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        $cut = substr($output, 0, self::MAX_OUTPUT_BYTES);

        // A UTF-8 sequence is at most 4 bytes; if the cut landed inside one,
        // drop the dangling lead so the body stays valid UTF-8.
        for ($i = strlen($cut) - 1; $i >= strlen($cut) - 4 && $i >= 0; $i--) {
            $byte = ord($cut[$i]);

            if ($byte < 0x80) {
                break;
            }

            if ($byte >= 0xC0) {
                $sequenceLength = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                if (strlen($cut) - $i < $sequenceLength) {
                    $cut = substr($cut, 0, $i);
                }

                break;
            }
        }

        return $cut;
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
