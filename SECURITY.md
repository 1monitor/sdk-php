# Security Policy

## Supported versions

The latest `0.x` release is the only supported version. Fixes ship in a new release rather than as patches to older tags.

## Reporting a vulnerability

**Do not open a public issue.**

Report privately through either channel:

- [GitHub private vulnerability reporting](https://github.com/1monitor/sdk-php/security/advisories/new) — preferred.
- Email <support@1monitor.io> with `SECURITY` in the subject.

Please include what you found, how to reproduce it, and the impact you think it has. A proof of concept helps.

## What to expect

- Acknowledgement within 3 working days.
- An assessment and a fix plan within 10 working days.
- A release plus a GitHub Security Advisory once a fix is available. We are happy to credit you unless you would rather stay anonymous.

Please give us a chance to ship a fix before disclosing publicly.

## Scope

This repository — the PHP SDK. Vulnerabilities in the 1Monitor service itself go to the same addresses; say which one you mean.

## How the SDK handles the ping token

A monitor's ping token is a credential: anyone holding it can ping that monitor. Keep it in environment variables or a secret store rather than in source, and rotate the monitor's token if one leaks.

The SDK is built to keep the token from leaking on its side:

- It never appears in the log context. HTTP client exceptions carry the request URL, so the SDK logs only the exception class and a message with the token redacted, never the exception object.
- It is never thrown. The only exceptions the SDK raises describe misconfiguration and do not include the token.
- Over the default Guzzle transport, TLS certificates are verified and a redirect from `https` to `http` is refused, so the token is not downgraded to clear text.
- Base URLs with embedded credentials are rejected, because the base URL is logged on failure.

Ping output is sent as provided, capped at 10 KB. Send your job's diagnostic output, not its environment or configuration.
