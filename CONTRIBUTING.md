# Contributing

Thank you for helping improve the 1Monitor PHP SDK.

## Reporting

- **Bugs and feature requests:** open a [GitHub issue](https://github.com/1monitor/sdk-php/issues/new/choose). Strip real ping tokens from any snippet you include.
- **Security vulnerabilities:** do not open an issue. Follow [SECURITY.md](SECURITY.md).

## Development

```bash
git clone git@github.com:1monitor/sdk-php.git
cd sdk-php
composer install
composer check
```

`composer check` runs the three gates CI enforces on every pull request, across PHP 8.1 to 8.5 and against both the newest and the oldest allowed dependency versions:

| Command | Tool |
|---|---|
| `composer cs` | PHP_CodeSniffer, PSR-12 (`composer cs-fix` fixes what it can) |
| `composer stan` | PHPStan at level `max` |
| `composer test` | PHPUnit |

## Pull requests

- Keep a pull request to one change. Small, focused diffs get reviewed faster.
- Add or update tests for any behaviour change. The suite runs against a mocked HTTP layer and needs no network access.
- Record user-visible changes in `CHANGELOG.md` under *Unreleased*. Internal refactors do not need an entry.
- Do not bump `Client::VERSION`; that happens in the release commit.

## Design constraints

A few rules shape this SDK. A change that breaks one of them will not be merged, however well tested.

- **Delivering a ping never throws.** Transport and HTTP failures return `false`. The only exceptions are `OneMonitor\Sdk\Exception\InvalidArgumentException` for misconfiguration or an empty token.
- **A ping cannot hold a job up for longer than the configured budget plus backoff.** Retries draw down a shared timeout instead of each getting their own.
- **The ping token is a credential.** It must never reach the log, an exception message that gets logged, or an unencrypted connection the caller did not ask for.
- **PSR interfaces at the boundary.** The SDK depends on PSR-3, PSR-17 and PSR-18. Guzzle is the default implementation, not a requirement of the public API.

## Releasing

Maintainers only.

1. Move the *Unreleased* entries in `CHANGELOG.md` under a new version heading with today's date, and update the comparison links at the bottom.
2. Set `Client::VERSION` to the same version.
3. Commit, tag the commit with the bare version (for example `0.4.0`), and push the tag. The release workflow fails if the tag, the constant and the changelog disagree.
4. Packagist picks the tag up automatically.
