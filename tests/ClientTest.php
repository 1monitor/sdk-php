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
use OneMonitor\Sdk\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
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

    public function testPingSendsTheSdkUserAgent(): void
    {
        $requests = [];
        $client = self::clientFor([new Response(200)], $requests);

        $client->ping('tok_abc');

        self::assertSame('1monitor-sdk-php/' . Client::VERSION, $requests[0]->getHeaderLine('User-Agent'));
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
    }

    #[DataProvider('malformedBaseUrls')]
    public function testMalformedBaseUrlThrows(string $baseUrl): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(baseUrl: $baseUrl);
    }

    public function testNonPositiveTimeoutThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(timeout: 0.0);
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
