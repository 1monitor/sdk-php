<?php

declare(strict_types=1);

namespace OneMonitor\Sdk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\HttpFactory;
use OneMonitor\Sdk\Exception\InvalidArgumentException;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Sends pings to 1Monitor.
 *
 * Delivery never throws: every ping method returns `true` when 1Monitor
 * accepted the ping and `false` otherwise. Only misconfiguration throws.
 */
final class Client implements ClientInterface
{
    public const VERSION = '0.3.0';

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

    /** Guzzle only: how many redirects one attempt may follow. */
    private const MAX_REDIRECTS = 5;

    /** Replaces the ping token wherever it would otherwise reach the log. */
    private const REDACTED = '[redacted]';

    private readonly string $baseUrl;

    /**
     * Guzzle only: schemes a redirect may lead to. An `https` base URL never
     * downgrades to `http`, so the token cannot be sent in clear text.
     *
     * @var list<string>
     */
    private readonly array $redirectProtocols;

    private readonly LoggerInterface $logger;

    private readonly HttpClientInterface $httpClient;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param string $baseUrl Where pings are sent. An `http` or `https` URL
     *     with an optional path; credentials, a query string or a fragment
     *     are rejected.
     * @param float $timeout Overall deadline for one ping across all attempts,
     *     in seconds. With the default (or any Guzzle) client it is enforced
     *     per attempt too; a non-Guzzle PSR-18 client brings its own socket
     *     timeouts (PSR-18 has no per-request options), and the budget then
     *     only prevents further retries from starting.
     * @param int $retries Extra attempts after the first one fails. 0 disables retrying.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $retries = self::DEFAULT_RETRIES,
        ?LoggerInterface $logger = null,
        ?HttpClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException(
                sprintf('Timeout must be a finite number greater than 0, %s given.', var_export($timeout, true)),
            );
        }

        if ($retries < 0) {
            throw new InvalidArgumentException(sprintf('Retries must not be negative, %d given.', $retries));
        }

        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->redirectProtocols = str_starts_with($this->baseUrl, 'https://') ? ['https'] : ['http', 'https'];
        $this->logger = $logger ?? new NullLogger();
        $this->httpClient = $httpClient ?? new GuzzleClient();

        $factory = new HttpFactory();
        $this->requestFactory = $requestFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
    }

    public function ping(string $token, ?int $exitCode = null, ?string $output = null): bool
    {
        return $this->send($token, PingState::Ping, $exitCode, $output);
    }

    public function pingStart(string $token): bool
    {
        return $this->send($token, PingState::Start, null, null);
    }

    public function pingSuccess(string $token, ?int $exitCode = null, ?string $output = null): bool
    {
        return $this->send($token, PingState::Success, $exitCode, $output);
    }

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
        // is held up: no retry starts once the budget is spent, and a Guzzle
        // client additionally gets the remaining budget as each attempt's
        // socket timeout. Backoff sleeps are deliberately outside the budget.
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

        // The token is a credential, so neither it nor anything that carries it
        // may reach the log: the exception object is not passed on (HTTP client
        // exceptions embed the request and its URL) and its message is redacted.
        $this->logger->error('1Monitor ping failed.', [
            'state' => $state->value,
            'baseUrl' => $this->baseUrl,
            'attempts' => $attempt,
            'status' => $status,
            'error' => $error === null ? null : $error::class,
            'reason' => $error === null ? null : self::redact($error->getMessage(), $token),
        ]);

        return false;
    }

    /**
     * One HTTP attempt.
     *
     * A Guzzle client — the default, or any injected one — is driven through
     * `request()` so the remaining budget caps this attempt's socket timeout
     * and redirects are followed. Guzzle's own PSR-18 adapter offers neither:
     * PSR-18 has no per-request options and its `sendRequest()` disables
     * redirect following.
     *
     * @return array{0: int|null, 1: Throwable|null, 2: float} status (null on transport
     *     failure), the error if any, and seconds spent
     */
    private function attempt(string $url, ?string $body, float $budget): array
    {
        if ($this->httpClient instanceof GuzzleClientInterface) {
            return $this->attemptWithGuzzle($this->httpClient, $url, $body, $budget);
        }

        return $this->attemptWithPsr18($url, $body);
    }

    /** @return array{0: int|null, 1: Throwable|null, 2: float} */
    private function attemptWithGuzzle(
        GuzzleClientInterface $client,
        string $url,
        ?string $body,
        float $budget,
    ): array {
        $startedAt = microtime(true);

        $headers = [
            'User-Agent' => self::userAgent(),
        ];

        $options = [
            'timeout' => $budget,
            'connect_timeout' => $budget,
            'http_errors' => false,
            'allow_redirects' => [
                'max' => self::MAX_REDIRECTS,
                'protocols' => $this->redirectProtocols,
            ],
        ];

        if ($body !== null) {
            $headers['Content-Type'] = 'text/plain; charset=utf-8';
            $options['body'] = $body;
        }

        $options['headers'] = $headers;

        try {
            $response = $client->request($body === null ? 'GET' : 'POST', $url, $options);

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

    /** @return array{0: int|null, 1: Throwable|null, 2: float} */
    private function attemptWithPsr18(string $url, ?string $body): array
    {
        $startedAt = microtime(true);

        $request = $this->requestFactory
            ->createRequest($body === null ? 'GET' : 'POST', $url)
            ->withHeader('User-Agent', self::userAgent());

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->httpClient->sendRequest($request);

            return [$response->getStatusCode(), null, microtime(true) - $startedAt];
        } catch (Throwable $e) {
            // PSR-18 forbids throwing on HTTP error statuses, so anything
            // caught here is a transport failure with no response to inspect.
            return [null, $e, microtime(true) - $startedAt];
        }
    }

    private static function userAgent(): string
    {
        return sprintf('1monitor-sdk-php/%s (PHP %s)', self::VERSION, PHP_VERSION);
    }

    /**
     * Removes the token from a message, in both the raw form and the form it
     * takes in the request URL.
     */
    private static function redact(string $message, string $token): string
    {
        return str_replace(
            array_unique([$token, rawurlencode($token)]),
            self::REDACTED,
            $message,
        );
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

    /**
     * Accepts an `http(s)` URL with an optional path. Credentials are rejected
     * because the base URL is logged on failure; a query string or fragment is
     * rejected because the ping path is appended to the URL as is.
     *
     * @throws InvalidArgumentException
     */
    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $normalized = rtrim(trim($baseUrl), '/');
        $parts = $normalized === '' ? false : parse_url($normalized);

        if (
            $parts === false
            || filter_var($normalized, FILTER_VALIDATE_URL) === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Base URL must be an http(s) URL without credentials, query or fragment, "%s" given.',
                    $baseUrl,
                ),
            );
        }

        return $normalized;
    }
}
