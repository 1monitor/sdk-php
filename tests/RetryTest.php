<?php

declare(strict_types=1);

namespace OneMonitor\Sdk\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OneMonitor\Sdk\Client;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RetryTest extends TestCase
{
    public function testServerErrorIsRetriedAndCanSucceed(): void
    {
        $handler = new MockHandler([new Response(503), new Response(200)]);
        $client = self::clientFor($handler, retries: 1);

        self::assertTrue($client->ping('tok_abc'));
        self::assertSame(0, $handler->count());
    }

    public function testConnectErrorIsRetriedAndCanSucceed(): void
    {
        $handler = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'https://ping.1monitor.io')),
            new Response(200),
        ]);
        $client = self::clientFor($handler, retries: 1);

        self::assertTrue($client->ping('tok_abc'));
        self::assertSame(0, $handler->count());
    }

    public function testRetryWaitsForTheBackoff(): void
    {
        $handler = new MockHandler([new Response(500), new Response(200)]);
        $client = self::clientFor($handler, retries: 1);

        $startedAt = microtime(true);
        $client->ping('tok_abc');

        self::assertGreaterThanOrEqual(0.45, microtime(true) - $startedAt);
    }

    public function testClientErrorIsNotRetried(): void
    {
        $handler = new MockHandler([new Response(404), new Response(200)]);
        $client = self::clientFor($handler, retries: 2);

        self::assertFalse($client->ping('tok_abc'));
        self::assertSame(1, $handler->count(), 'the second queued response must stay untouched');
    }

    public function testRateLimitIsNotRetried(): void
    {
        $handler = new MockHandler([new Response(429), new Response(200)]);
        $client = self::clientFor($handler, retries: 2);

        self::assertFalse($client->ping('tok_abc'));
        self::assertSame(1, $handler->count());
    }

    public function testZeroRetriesMakesASingleAttempt(): void
    {
        $handler = new MockHandler([new Response(500), new Response(200)]);
        $client = self::clientFor($handler, retries: 0);

        self::assertFalse($client->ping('tok_abc'));
        self::assertSame(1, $handler->count());
    }

    public function testNoRetryStartsOnceTheTimeBudgetIsSpent(): void
    {
        $attempts = 0;
        $slowHandler = static function (RequestInterface $request, array $options) use (&$attempts): PromiseInterface {
            $attempts++;
            usleep(120_000);

            return self::respondWith(new Response(500));
        };

        $client = new Client(
            timeout: 0.1,
            retries: 5,
            httpClient: new GuzzleClient(['handler' => $slowHandler]),
        );

        $startedAt = microtime(true);

        self::assertFalse($client->ping('tok_abc'));
        self::assertSame(1, $attempts, 'the first attempt spent the whole budget');
        self::assertLessThan(0.45, microtime(true) - $startedAt, 'no backoff sleep for a retry that never happens');
    }

    public function testTheTimeBudgetIsSharedAcrossAttempts(): void
    {
        $attempts = 0;
        $handler = static function (RequestInterface $request, array $options) use (&$attempts): PromiseInterface {
            $attempts++;
            usleep(60_000);

            return self::respondWith(new Response(500));
        };

        $client = new Client(
            timeout: 0.1,
            retries: 5,
            httpClient: new GuzzleClient(['handler' => $handler]),
        );

        self::assertFalse($client->ping('tok_abc'));
        self::assertSame(2, $attempts, 'the two attempts together spend the budget, so the other retries never start');
    }

    /**
     * Typed as the promise a Guzzle handler must produce; inferring the type
     * from a concrete Response would not satisfy the invariant template.
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private static function respondWith(ResponseInterface $response): PromiseInterface
    {
        return new FulfilledPromise($response);
    }

    private static function clientFor(MockHandler $handler, int $retries): Client
    {
        return new Client(
            retries: $retries,
            httpClient: new GuzzleClient(['handler' => HandlerStack::create($handler)]),
        );
    }
}
