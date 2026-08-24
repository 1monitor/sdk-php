# 1Monitor SDK for PHP

[![CI](https://github.com/1monitor/sdk-php/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/1monitor/sdk-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/1monitor/sdk-php.svg)](https://packagist.org/packages/1monitor/sdk-php)
[![PHP](https://img.shields.io/packagist/dependency-v/1monitor/sdk-php/php.svg)](https://packagist.org/packages/1monitor/sdk-php)
[![License](https://img.shields.io/packagist/l/1monitor/sdk-php.svg)](LICENSE)

Official PHP SDK for [1Monitor](https://1monitor.io) — ping your cron jobs, queue workers and scheduled tasks so you find out when they stop running.

```php
$client = new OneMonitor\Sdk\Client();
$client->ping('your-monitor-token');
```

**Sending a ping never throws and never blocks for long.** Monitoring must not be the thing that takes your job down. See [The no-throw guarantee](#the-no-throw-guarantee).

## Requirements

PHP 8.1 or newer.

## Install

```bash
composer require 1monitor/sdk-php:^0.3
```

## Quickstart

Create a monitor in 1Monitor, copy its ping token, and call the SDK from the job you want watched.

```php
use OneMonitor\Sdk\Client;

$client = new Client();

$client->ping('tok_abc');   // "this job is alive"
```

That single call is enough for a heartbeat monitor: 1Monitor alerts you when the pings stop arriving.

### Measuring how long a run takes

If you also want to know that a job *finished*, and how long it took, wrap the work in a start/finish pair. 1Monitor pairs them into a **run**, records its duration, and can alert on runs that fail or overrun.

```php
use OneMonitor\Sdk\Client;

$client = new Client();
$token = 'tok_abc';

$client->pingStart($token);

try {
    doTheWork();
    $client->pingSuccess($token);
} catch (Throwable $e) {
    $client->pingFail($token, output: $e->getMessage());

    throw $e;
}
```

Put the `pingFail` before the rethrow, not in a `finally`, so a crash still closes the run — and so the exception keeps propagating exactly as it would without the SDK.

### Saying *why* it failed

`ping`, `pingSuccess` and `pingFail` optionally carry the job's exit code and output, so the alert can tell you what went wrong instead of just that something did:

```php
$exitCode = 0;
$stderr = '';

// ... run the job, capturing $exitCode and $stderr ...

if ($exitCode === 0) {
    $client->pingSuccess($token, exitCode: $exitCode);
} else {
    $client->pingFail($token, exitCode: $exitCode, output: $stderr);
}
```

The exit code travels as `?exit_code=N`; a non-zero value marks the ping as failed even on a bare `ping()`. The output travels as the request body and is capped at 10 KB (`Client::MAX_OUTPUT_BYTES`) — the SDK truncates longer output before sending, because that is all the server would keep anyway.

1Monitor sweeps common credential shapes (tokens, API keys, private keys) out of ping output before storing it, but that is a safety net, not permission: send your job's diagnostic output, not its environment or its config.

### From a cron job

The same pattern as a standalone script, wired into your crontab:

```php
#!/usr/bin/env php
<?php // bin/nightly-report

require __DIR__ . '/../vendor/autoload.php';

use OneMonitor\Sdk\Client;

$client = new Client();
$token = getenv('ONEMONITOR_TOKEN') ?: '';

$client->pingStart($token);

try {
    (new NightlyReport())->run();
    $client->pingSuccess($token);
} catch (Throwable $e) {
    $client->pingFail($token);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);

    exit(1);
}
```

```cron
0 3 * * * ONEMONITOR_TOKEN=tok_abc /srv/app/bin/nightly-report
```

Keep the token in the environment rather than in the source — it is a credential: anyone holding it can ping your monitor.

## The four calls

| Method | URL | Meaning |
|---|---|---|
| `ping($token, ?$exitCode, ?$output)` | `/ping/{token}` | The job is alive. |
| `pingStart($token)` | `/ping/{token}/start` | A run has started. |
| `pingSuccess($token, ?$exitCode, ?$output)` | `/ping/{token}/success` | The open run finished successfully. |
| `pingFail($token, ?$exitCode, ?$output)` | `/ping/{token}/fail` | The open run failed. |

Each returns `bool`: `true` when 1Monitor accepted the ping, `false` when it did not.

`exitCode` and `output` are optional and named. A ping with output is sent as a `POST` with the output as the body; without one it stays a `GET`. `pingStart` carries neither — the job has not produced a result yet. Exit codes outside 0–255 are ignored by the server.

## Options

Every constructor option is optional and named:

```php
$client = new OneMonitor\Sdk\Client(
    timeout: 2.0,
    retries: 1,
    logger: $psrLogger,
);
```

| Option | Type | Default | What it does |
|---|---|---|---|
| `baseUrl` | `string` | `https://ping.1monitor.io` | Where pings are sent. Must be a valid `http`/`https` URL. |
| `timeout` | `float` | `5.0` | Seconds of network time **shared across all attempts** — see below. |
| `retries` | `int` | `2` | Extra attempts after the first one fails. `0` disables retrying. |
| `logger` | `?Psr\Log\LoggerInterface` | none | Where failed pings are reported. Without one, failures are silent. |
| `httpClient` | `?Psr\Http\Client\ClientInterface` | a plain Guzzle client | Any [PSR-18](https://www.php-fig.org/psr/psr-18/) client — for a proxy, custom TLS, or a different HTTP stack. A Guzzle client gets extra care — see below. |
| `requestFactory` | `?Psr\Http\Message\RequestFactoryInterface` | `guzzlehttp/psr7` | Builds the ping requests (PSR-17). |
| `streamFactory` | `?Psr\Http\Message\StreamFactoryInterface` | `guzzlehttp/psr7` | Builds the output body streams (PSR-17). |

**Maximum time a ping can hold up your job: `timeout` + backoff sleeps — about 6.5 s with the defaults** (5 s of network time, plus 0.5 s and 1 s between the three attempts).

### Why `timeout` is a budget, not a per-attempt limit

`timeout` is an overall deadline for time spent on the wire. Attempts draw it down as they run, and no retry starts once the budget is gone.

That is deliberate. If `timeout` only limited single attempts, three attempts against a hanging endpoint would block for 3 × 5 s plus backoff — around 16.5 s of your job's runtime spent on monitoring. The shared budget keeps the worst case flat no matter how many retries you allow.

### Guzzle clients vs. other PSR-18 clients

The SDK drives a Guzzle client — the default, or any injected one — through Guzzle's own request API rather than its PSR-18 adapter, because the adapter supports neither per-request timeouts nor redirects. With Guzzle you therefore get the full guarantees: each attempt's socket timeout is the *remaining* budget (so the 6.5 s worst case above holds), and redirects are followed.

With a non-Guzzle PSR-18 client, two things are yours to handle:

- **Socket timeouts** — PSR-18 has no per-request options, so configure them on the client. The SDK still refuses to start a retry once the budget is spent, but it cannot cut short an attempt already in flight; a hanging endpoint can then hold the job for one full attempt beyond the budget.
- **Redirects** — followed only if your client follows them. A `3xx` reaching the SDK counts as a failed ping and is not retried, so point `baseUrl` directly at the ping endpoint over `https`.

### What gets retried

Only connection failures and `5xx` responses. A `4xx` is a decision, not a hiccup — a wrong token stays wrong, and a `429` means you are already sending too much — so those fail immediately. Backoff is roughly 0.5 s, then 1 s.

### Ping constraints

If a monitor requires a specific HTTP method or header, inject an HTTP client configured with them — any PSR-18 client works, Guzzle shown here:

```php
$client = new OneMonitor\Sdk\Client(
    httpClient: new GuzzleHttp\Client([
        'timeout' => 5.0,
        'headers' => ['X-Deploy-Key' => 'secret'],
    ]),
);
```

First-class options for this may be added later.

## The no-throw guarantee

The SDK exists to tell you when a job stopped working. It must never be the reason a job stops working.

So **delivering a ping never throws**. A DNS failure, a dead network, a TLS error, an unreachable 1Monitor, a `404` from a deleted monitor, a `429`, a `500` — all of them return `false`. Nothing propagates into your job.

```php
if (!$client->pingSuccess($token)) {
    // The work is done regardless. Whether you care is up to you.
}
```

Failures are reported to the PSR-3 logger you pass in, at `error` level, with the state, base URL, attempt count, status code and underlying exception in the context. The ping token is deliberately kept out of the log context — it is a credential. Without a logger, failures are dropped silently.

The only exceptions the SDK throws are your own mistakes, caught early and loudly:

| Thrown | When |
|---|---|
| `OneMonitor\Sdk\Exception\InvalidArgumentException` | Malformed `baseUrl`, non-positive `timeout`, or negative `retries` — at construction. |
| `OneMonitor\Sdk\Exception\InvalidArgumentException` | Empty ping token — at the call. |

Both implement `OneMonitor\Sdk\Exception\Exception`, so `catch (OneMonitor\Sdk\Exception\Exception $e)` catches everything this package throws.

## Versioning

This package is at **0.x**, and Composer treats `0.x` minors as breaking. Require it as:

```json
"1monitor/sdk-php": "^0.3"
```

The version is deliberately below `1.0.0`: the SDK's stability promise cannot exceed that of the ping API it wraps, which is not yet formally versioned. `1.0.0` follows once it is.

Changes are recorded in [CHANGELOG.md](CHANGELOG.md).

## Scope

Ping only. Management operations — creating, listing or pausing monitors, reading alerts — are not covered; there is no public management API yet. They will be added to this same package when there is, which is why it is named `sdk-php` and not `ping-client`.

## Contributing

Bug reports and feature requests are welcome in [GitHub Issues](https://github.com/1monitor/sdk-php/issues). Security issues follow a separate process — see [SECURITY.md](SECURITY.md).

Local development:

```bash
composer install
composer test   # PHPUnit
composer stan   # PHPStan, level max
composer cs     # PHP_CodeSniffer, PSR-12
```

## License

MIT — see [LICENSE](LICENSE).
