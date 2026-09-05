<?php

declare(strict_types=1);

namespace OneMonitor\Sdk\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OneMonitor\Sdk\Client;
use OneMonitor\Sdk\ClientInterface as SdkClientInterface;
use OneMonitor\Sdk\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class ClientTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function pingMethods(): iterable
    {
        yield 'ping' => ['ping', 'https://ping.1monitor.io/ping/tok_abc'];
        yield 'pingStart' => ['pingStart', 'https://ping.1monitor.io/ping/tok_abc/start'];
        yield 'pingSuccess' => ['pingSuccess', 'https://ping.1monitor.io/ping/tok_abc/success'];
        yield 'pingFail' => ['pingFail', 'https://ping.1monitor.io/ping/tok_abc/fail'];
    }

    #[DataProvider('pingMethods')]
    public function testPingMethodHitsItsUrlAndReportsSuccess(string $method, string $expectedUrl): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200, [], 'OK')], $requests);

        self::assertTrue($client->{$method}('tok_abc'));
        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]->getMethod());
        self::assertSame($expectedUrl, (string) $requests[0]->getUri());
    }

    /** @return iterable<string, array{string, string}> */
    public static function resultCarryingMethods(): iterable
    {
        yield 'ping' => ['ping', 'https://ping.1monitor.io/ping/tok_abc'];
        yield 'pingSuccess' => ['pingSuccess', 'https://ping.1monitor.io/ping/tok_abc/success'];
        yield 'pingFail' => ['pingFail', 'https://ping.1monitor.io/ping/tok_abc/fail'];
    }

    #[DataProvider('resultCarryingMethods')]
    public function testExitCodeIsSentAsAQueryParameter(string $method, string $expectedUrl): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        self::assertTrue($client->{$method}('tok_abc', exitCode: 42));
        self::assertSame($expectedUrl . '?exit_code=42', (string) $requests[0]->getUri());
        self::assertSame('GET', $requests[0]->getMethod());
    }

    public function testExitCodeZeroIsStillSent(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        $client->pingSuccess('tok_abc', exitCode: 0);

        self::assertSame(
            'https://ping.1monitor.io/ping/tok_abc/success?exit_code=0',
            (string) $requests[0]->getUri(),
        );
    }

    public function testWithoutAnExitCodeThereIsNoQueryString(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        $client->pingFail('tok_abc');

        self::assertSame('https://ping.1monitor.io/ping/tok_abc/fail', (string) $requests[0]->getUri());
    }

    #[DataProvider('resultCarryingMethods')]
    public function testOutputIsPostedAsTheRequestBody(string $method, string $expectedUrl): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        self::assertTrue($client->{$method}('tok_abc', output: "error: disk full\n"));
        self::assertSame('POST', $requests[0]->getMethod());
        self::assertSame($expectedUrl, (string) $requests[0]->getUri());
        self::assertSame("error: disk full\n", (string) $requests[0]->getBody());
        self::assertSame('text/plain; charset=utf-8', $requests[0]->getHeaderLine('Content-Type'));
    }

    public function testExitCodeAndOutputTravelTogether(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        $client->pingFail('tok_abc', exitCode: 1, output: 'boom');

        self::assertSame('POST', $requests[0]->getMethod());
        self::assertSame(
            'https://ping.1monitor.io/ping/tok_abc/fail?exit_code=1',
            (string) $requests[0]->getUri(),
        );
        self::assertSame('boom', (string) $requests[0]->getBody());
    }

    public function testEmptyOutputSendsAPlainGetPing(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        $client->pingFail('tok_abc', output: '');

        self::assertSame('GET', $requests[0]->getMethod());
        self::assertSame('', (string) $requests[0]->getBody());
    }

    public function testOversizedOutputIsTruncatedBeforeSending(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        $client->pingFail('tok_abc', output: str_repeat('x', Client::MAX_OUTPUT_BYTES + 500));

        self::assertSame(Client::MAX_OUTPUT_BYTES, strlen((string) $requests[0]->getBody()));
    }

    public function testTruncationDoesNotSplitAMultibyteCharacter(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        // The euro sign is 3 bytes; starting it 1 byte before the cap would split it.
        $output = str_repeat('a', Client::MAX_OUTPUT_BYTES - 1) . '€€€';
        $client->pingFail('tok_abc', output: $output);

        $body = (string) $requests[0]->getBody();
        self::assertSame(Client::MAX_OUTPUT_BYTES - 1, strlen($body));
        self::assertSame('a', substr($body, -1));
    }

    public function testOutputWithinTheCapIsNotTouched(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        $output = str_repeat('€', intdiv(Client::MAX_OUTPUT_BYTES, 3));
        $client->pingFail('tok_abc', output: $output);

        self::assertSame($output, (string) $requests[0]->getBody());
    }

    public function testTheOutputCapMirrorsTheServerLimit(): void
    {
        self::assertSame(10 * 1024, Client::MAX_OUTPUT_BYTES);
    }

    public function testTheLoggedContextDoesNotLeakTheOutput(): void
    {
        $logger = new RecordingLogger();
        $requests = [];
        $client = self::clientFor([new Response(500)], $requests, retries: 0, logger: $logger);

        $client->pingFail('tok_abc', exitCode: 1, output: 'password=hunter2');

        self::assertStringNotContainsString('hunter2', json_encode($logger->records[0]['context']) ?: '');
    }

    public function testPingSendsTheSdkUserAgent(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        $client->ping('tok_abc');

        self::assertSame(
            sprintf('1monitor-sdk-php/%s (PHP %s)', Client::VERSION, PHP_VERSION),
            $requests[0]->getHeaderLine('User-Agent'),
        );
    }

    public function testNoContentCountsAsDelivered(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(204)], $requests);

        self::assertTrue($client->ping('tok_abc'));
    }

    /** @return iterable<string, array{int}> */
    public static function failingStatuses(): iterable
    {
        yield 'bad request' => [400];
        yield 'not found' => [404];
        yield 'rate limited' => [429];
        yield 'server error' => [500];
        yield 'bad gateway' => [502];
    }

    #[DataProvider('failingStatuses')]
    public function testFailingStatusReturnsFalseWithoutThrowing(int $status): void
    {
        $requests = [];
        $client = self::clientFor(array_fill(0, 3, new Response($status)), $requests, retries: 0);

        self::assertFalse($client->ping('tok_abc'));
    }

    public function testTransportFailureReturnsFalseWithoutThrowing(): void
    {
        $requests = [];
        $client = self::clientFor(
            [new ConnectException('Connection refused', new Request('GET', 'https://ping.1monitor.io'))],
            $requests,
            retries: 0,
        );

        self::assertFalse($client->ping('tok_abc'));
    }

    public function testUnexpectedHttpClientExceptionIsSwallowed(): void
    {
        $client = new Client(
            retries: 0,
            httpClient: new GuzzleClient([
                'handler' => static function (): never {
                    throw new RuntimeException('the injected client blew up');
                },
            ]),
        );

        self::assertFalse($client->ping('tok_abc'));
    }

    public function testAGuzzleClientFollowsRedirects(): void
    {
        $requests = [];
        $client = self::clientFor(
            [
                new Response(301, ['Location' => 'https://ping.1monitor.io/ping/tok_abc']),
                new Response(200),
            ],
            $requests,
        );

        self::assertTrue($client->ping('tok_abc'));
        self::assertCount(2, $requests, 'the redirect must be followed, not treated as a failure');
    }

    public function testAGuzzleClientRefusesARedirectFromHttpsToHttp(): void
    {
        $requests = [];
        $client = self::clientFor(
            [
                new Response(301, ['Location' => 'http://ping.1monitor.io/ping/tok_abc']),
                new Response(200),
            ],
            $requests,
        );

        self::assertFalse($client->ping('tok_abc'), 'the token must not be sent in clear text');
        self::assertCount(1, $requests, 'the downgraded redirect must not be followed');
        self::assertSame('https', $requests[0]->getUri()->getScheme());
    }

    public function testAGuzzleClientFollowsAnUpgradeFromHttpToHttps(): void
    {
        $requests = [];
        $client = self::clientFor(
            [
                new Response(301, ['Location' => 'https://ping.example.test/ping/tok_abc']),
                new Response(200),
            ],
            $requests,
            baseUrl: 'http://ping.example.test',
        );

        self::assertTrue($client->ping('tok_abc'));
        self::assertCount(2, $requests);
        self::assertSame('https', $requests[1]->getUri()->getScheme());
    }

    public function testAPsr18ClientReportsARedirectAsAFailureWithoutRetrying(): void
    {
        $attempts = 0;
        $httpClient = new class ($attempts) implements ClientInterface {
            /** @param int $attempts counted by reference */
            public function __construct(private int &$attempts)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->attempts++;

                return new Response(301, ['Location' => 'https://elsewhere.example']);
            }
        };

        $client = new Client(httpClient: $httpClient);

        self::assertFalse($client->ping('tok_abc'));
        self::assertSame(1, $attempts, 'a redirect is not transient, so it must not be retried');
    }

    public function testAnyPsr18ClientCanBeInjected(): void
    {
        $httpClient = new class implements ClientInterface {
            public ?RequestInterface $lastRequest = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->lastRequest = $request;

                return new Response(200);
            }
        };

        $client = new Client(httpClient: $httpClient);

        self::assertTrue($client->ping('tok_abc'));
        self::assertNotNull($httpClient->lastRequest);
        self::assertSame('https://ping.1monitor.io/ping/tok_abc', (string) $httpClient->lastRequest->getUri());
    }

    public function testFailureIsReportedToTheLogger(): void
    {
        $logger = new RecordingLogger();
        $requests = [];
        $client = self::clientFor([new Response(404)], $requests, retries: 0, logger: $logger);

        $client->pingFail('tok_abc');

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('1Monitor ping failed.', $logger->records[0]['message']);
        self::assertSame('fail', $logger->records[0]['context']['state']);
        self::assertSame(404, $logger->records[0]['context']['status']);
        self::assertSame(1, $logger->records[0]['context']['attempts']);
    }

    public function testTheLoggedContextDoesNotLeakTheToken(): void
    {
        $logger = new RecordingLogger();
        $requests = [];
        $client = self::clientFor([new Response(500)], $requests, retries: 0, logger: $logger);

        $client->ping('tok_secret');

        self::assertStringNotContainsString('tok_secret', json_encode($logger->records[0]['context']) ?: '');
    }

    public function testATransportErrorMentioningTheUrlIsRedactedBeforeLogging(): void
    {
        // Guzzle's real ConnectException messages end with the request URL, and
        // the exception object itself carries the whole request.
        $request = new Request('GET', 'https://ping.1monitor.io/ping/tok_secret');
        $exception = new ConnectException(
            'cURL error 6: Could not resolve host for https://ping.1monitor.io/ping/tok_secret',
            $request,
        );

        $logger = new RecordingLogger();
        $requests = [];
        $client = self::clientFor([$exception], $requests, retries: 0, logger: $logger);

        self::assertFalse($client->ping('tok_secret'));

        $context = $logger->records[0]['context'];
        self::assertSame(ConnectException::class, $context['error']);
        self::assertSame(
            'cURL error 6: Could not resolve host for https://ping.1monitor.io/ping/[redacted]',
            $context['reason'],
        );
        self::assertNull($context['status']);

        foreach ($context as $value) {
            self::assertIsScalar($value ?? '', 'no object that could carry the request may reach the log');
        }
    }

    public function testTheUrlEncodedFormOfTheTokenIsRedactedToo(): void
    {
        $request = new Request('GET', 'https://ping.1monitor.io/ping/a%20b');
        $exception = new ConnectException('failed for https://ping.1monitor.io/ping/a%20b', $request);

        $logger = new RecordingLogger();
        $requests = [];
        $client = self::clientFor([$exception], $requests, retries: 0, logger: $logger);

        $client->ping('a b');

        self::assertSame(
            'failed for https://ping.1monitor.io/ping/[redacted]',
            $logger->records[0]['context']['reason'],
        );
    }

    public function testAPsr18TransportErrorIsLoggedWithoutTheException(): void
    {
        $httpClient = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('socket closed while sending ' . $request->getUri());
            }
        };

        $logger = new RecordingLogger();
        $client = new Client(retries: 0, logger: $logger, httpClient: $httpClient);

        self::assertFalse($client->ping('tok_secret'));

        $context = $logger->records[0]['context'];
        self::assertSame(RuntimeException::class, $context['error']);
        self::assertSame(
            'socket closed while sending https://ping.1monitor.io/ping/[redacted]',
            $context['reason'],
        );
    }

    public function testASuccessfulResponseLeavesNoErrorInTheLog(): void
    {
        $logger = new RecordingLogger();
        $requests = [];
        $client = self::clientFor([new Response(404)], $requests, retries: 0, logger: $logger);

        $client->ping('tok_abc');

        self::assertNull($logger->records[0]['context']['error']);
        self::assertNull($logger->records[0]['context']['reason']);
    }

    public function testSuccessIsNotLogged(): void
    {
        $logger = new RecordingLogger();
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests, logger: $logger);

        $client->ping('tok_abc');

        self::assertSame([], $logger->records);
    }

    public function testFailureWithoutALoggerIsSilent(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(500)], $requests, retries: 0);

        self::assertFalse($client->ping('tok_abc'));
    }

    public function testTrailingSlashesInTheBaseUrlAreStripped(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests, baseUrl: 'https://ping.example.test//');

        $client->pingStart('tok_abc');

        self::assertSame('https://ping.example.test/ping/tok_abc/start', (string) $requests[0]->getUri());
    }

    public function testTokensAreUrlEncoded(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        $client->ping('a b/c');

        self::assertSame('https://ping.1monitor.io/ping/a%20b%2Fc', (string) $requests[0]->getUri());
    }

    /** @return iterable<string, array{string}> */
    public static function emptyTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \t "];
    }

    #[DataProvider('emptyTokens')]
    public function testEmptyTokenThrows(string $token): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ping token must not be empty.');

        $client->ping($token);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedBaseUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'no scheme' => ['ping.1monitor.io'];
        yield 'not a url' => ['not a url at all'];
        yield 'unsupported scheme' => ['ftp://ping.1monitor.io'];
        yield 'credentials' => ['https://user:secret@ping.1monitor.io'];
        yield 'user only' => ['https://user@ping.1monitor.io'];
        yield 'query string' => ['https://ping.1monitor.io/?key=1'];
        yield 'fragment' => ['https://ping.1monitor.io/#top'];
    }

    public function testTheBaseUrlMayCarryAPathPrefix(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests, baseUrl: 'https://proxy.example.test/1monitor/');

        $client->ping('tok_abc');

        self::assertSame('https://proxy.example.test/1monitor/ping/tok_abc', (string) $requests[0]->getUri());
    }

    #[DataProvider('malformedBaseUrls')]
    public function testMalformedBaseUrlThrows(string $baseUrl): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(baseUrl: $baseUrl);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('invalidTimeouts')]
    public function testInvalidTimeoutThrows(float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(timeout: $timeout);
    }

    public function testTheClientImplementsTheSdkInterface(): void
    {
        self::assertInstanceOf(SdkClientInterface::class, new Client());
    }

    public function testNegativeRetriesThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(retries: -1);
    }

    public function testDefaultsPointAtProduction(): void
    {
        self::assertSame('https://ping.1monitor.io', Client::DEFAULT_BASE_URL);
        self::assertSame(5.0, Client::DEFAULT_TIMEOUT);
        self::assertSame(2, Client::DEFAULT_RETRIES);
    }

    /**
     * @param list<Response|Throwable> $queue
     * @param list<RequestInterface>   $requests captured by reference
     */
    private static function clientFor(
        array $queue,
        array &$requests,
        string $baseUrl = Client::DEFAULT_BASE_URL,
        int $retries = Client::DEFAULT_RETRIES,
        ?RecordingLogger $logger = null,
    ): Client {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::tap(static function (RequestInterface $request) use (&$requests): void {
            $requests[] = $request;
        }));

        return new Client(
            baseUrl: $baseUrl,
            retries: $retries,
            logger: $logger,
            httpClient: new GuzzleClient(['handler' => $stack]),
        );
    }
}
