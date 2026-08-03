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

## A note on ping tokens

A monitor's ping token is a credential: anyone holding it can ping that monitor. The SDK keeps tokens out of its log context for that reason. Keep them in environment variables or a secret store rather than in source, and rotate the monitor's token if one leaks.
