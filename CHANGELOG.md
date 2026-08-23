# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the package is at `0.x`, minor releases may contain breaking changes.

## [Unreleased]

### Changed

- **Breaking:** the `httpClient` constructor option now takes any PSR-18 `Psr\Http\Client\ClientInterface` instead of `GuzzleHttp\ClientInterface`. Guzzle remains the default implementation; new optional `requestFactory` and `streamFactory` options accept PSR-17 factories (default: `guzzlehttp/psr7`).
- The per-attempt socket timeout now belongs to the HTTP client (PSR-18 has no per-request options). The default client is configured with `timeout`; injected clients must bring their own socket timeouts. The overall time budget still prevents a retry from starting once it is spent.
- The `User-Agent` header now includes the PHP version, e.g. `1monitor-sdk-php/0.2.0 (PHP 8.3.6)`.

## [0.2.0] - 2026-08-08

### Added

- `ping()`, `pingSuccess()` and `pingFail()` accept optional named `exitCode` and `output` arguments, so a ping can carry the job's exit code (`?exit_code=N`) and output (request body, sent as `POST`).
- `Client::MAX_OUTPUT_BYTES` (10 KB) — the server's payload cap, enforced client-side: longer output is truncated (without splitting a UTF-8 character) before sending.
- Guzzle 8 support: the package now requires `guzzlehttp/guzzle` `^7.0 || ^8.0`.

## [0.1.0] - 2026-08-03

Initial release.

### Added

- `OneMonitor\Sdk\Client` with `ping()`, `pingStart()`, `pingSuccess()` and `pingFail()`, each returning `bool`.
- Constructor options `baseUrl`, `timeout`, `retries`, `logger` (PSR-3) and `httpClient` (Guzzle).
- No-throw delivery: transport failures and non-2xx responses return `false` and are logged, never thrown.
- Retries on connection failures and `5xx` only, with 0.5 s / 1 s backoff, bounded by an overall time budget shared across attempts.
- `OneMonitor\Sdk\Exception\InvalidArgumentException` for misconfiguration and empty tokens.

[Unreleased]: https://github.com/1monitor/sdk-php/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/1monitor/sdk-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/1monitor/sdk-php/releases/tag/v0.1.0
