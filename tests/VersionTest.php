<?php

declare(strict_types=1);

namespace OneMonitor\Sdk\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OneMonitor\Sdk\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class VersionTest extends TestCase
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
    public function testVersionIsSentAsAQueryParameter(string $method, string $expectedUrl): void
    {
        $requests = [];
        $client = self::clientFor($requests);

        self::assertTrue($client->{$method}('tok_abc', version: '1.4.2'));
        self::assertSame($expectedUrl . '?version=1.4.2', (string) $requests[0]->getUri());
    }

    public function testVersionTravelsAfterTheExitCode(): void
    {
        $requests = [];
        $client = self::clientFor($requests);

        $client->pingFail('tok_abc', exitCode: 1, output: 'boom', version: 'abc123');

        self::assertSame('POST', $requests[0]->getMethod());
        self::assertSame(
            'https://ping.1monitor.io/ping/tok_abc/fail?exit_code=1&version=abc123',
            (string) $requests[0]->getUri(),
        );
    }

    public function testReservedCharactersInTheVersionAreUrlEncoded(): void
    {
        $requests = [];
        $client = self::clientFor($requests);

        $client->ping('tok_abc', version: 'v1+build&x=#1');

        self::assertSame(
            'https://ping.1monitor.io/ping/tok_abc?version=v1%2Bbuild%26x%3D%231',
            (string) $requests[0]->getUri(),
        );
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        $requests = [];
        $client = self::clientFor($requests);

        $client->ping('tok_abc', version: " 1.4.2\n");

        self::assertSame('https://ping.1monitor.io/ping/tok_abc?version=1.4.2', (string) $requests[0]->getUri());
    }

    public function testASixtyFourCharacterVersionIsSent(): void
    {
        $requests = [];
        $client = self::clientFor($requests);
        $sha = str_repeat('a', 64);

        $client->ping('tok_abc', version: $sha);

        self::assertSame('https://ping.1monitor.io/ping/tok_abc?version=' . $sha, (string) $requests[0]->getUri());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidVersions(): iterable
    {
        yield 'too long' => [str_repeat('a', 65)];
        yield 'inner space' => ['1.4 beta'];
        yield 'inner tab' => ["1.4\tbeta"];
        yield 'control character' => ["1.4\x01"];
        yield 'delete' => ["1.4\x7F"];
        yield 'non-ascii' => ['1.4-ß'];
    }

    #[DataProvider('invalidVersions')]
    public function testAnInvalidVersionIsDroppedAndLoggedAtDebug(string $version): void
    {
        $logger = new RecordingLogger();
        $requests = [];
        $client = self::clientFor($requests, logger: $logger);

        self::assertTrue($client->ping('tok_abc', version: $version));
        self::assertSame('https://ping.1monitor.io/ping/tok_abc', (string) $requests[0]->getUri());

        self::assertCount(1, $logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
        self::assertSame(
            '1Monitor ping version dropped: it must be 1-64 printable, non-whitespace ASCII characters.',
            $logger->records[0]['message'],
        );
        self::assertSame($version, $logger->records[0]['context']['version']);
    }

    /** @return iterable<string, array{string}> */
    public static function blankVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \t "];
    }

    #[DataProvider('blankVersions')]
    public function testABlankVersionSendsNoneAndLogsNothing(string $version): void
    {
        $logger = new RecordingLogger();
        $requests = [];
        $client = self::clientFor($requests, logger: $logger);

        $client->ping('tok_abc', version: $version);

        self::assertSame('https://ping.1monitor.io/ping/tok_abc', (string) $requests[0]->getUri());
        self::assertSame([], $logger->records);
    }

    #[DataProvider('pingMethods')]
    public function testTheClientDefaultVersionIsSentOnEveryPing(string $method, string $expectedUrl): void
    {
        $requests = [];
        $client = self::clientFor($requests, version: 'deadbeef');

        $client->{$method}('tok_abc');

        self::assertSame($expectedUrl . '?version=deadbeef', (string) $requests[0]->getUri());
    }

    public function testAPerCallVersionOverridesTheClientDefault(): void
    {
        $requests = [];
        $client = self::clientFor($requests, version: 'deadbeef');

        $client->ping('tok_abc', version: 'cafebabe');

        self::assertSame('https://ping.1monitor.io/ping/tok_abc?version=cafebabe', (string) $requests[0]->getUri());
    }

    public function testADroppedPerCallVersionFallsBackToTheClientDefault(): void
    {
        $requests = [];
        $client = self::clientFor($requests, version: 'deadbeef');

        $client->ping('tok_abc', version: 'not valid');

        self::assertSame('https://ping.1monitor.io/ping/tok_abc?version=deadbeef', (string) $requests[0]->getUri());
    }

    public function testAnInvalidClientDefaultIsDroppedOnceAtConstructionWithoutThrowing(): void
    {
        $logger = new RecordingLogger();
        $requests = [];
        $client = self::clientFor($requests, logger: $logger, version: 'not valid');

        self::assertCount(1, $logger->records);
        self::assertSame('debug', $logger->records[0]['level']);

        self::assertTrue($client->ping('tok_abc'));
        self::assertSame('https://ping.1monitor.io/ping/tok_abc', (string) $requests[0]->getUri());
        self::assertCount(1, $logger->records, 'the dropped default is not reported again on every ping');
    }

    /** @param list<RequestInterface> $requests captured by reference */
    private static function clientFor(
        array &$requests,
        ?RecordingLogger $logger = null,
        ?string $version = null,
    ): Client {
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $stack->push(Middleware::tap(static function (RequestInterface $request) use (&$requests): void {
            $requests[] = $request;
        }));

        return new Client(
            logger: $logger,
            httpClient: new GuzzleClient(['handler' => $stack]),
            version: $version,
        );
    }
}
