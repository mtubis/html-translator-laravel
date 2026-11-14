# Security Policy

## Supported versions

Only the latest minor release receives security fixes while the package is 0.x.

| Version | Supported |
|---------|-----------|
| 0.2.x   | Yes       |
| < 0.2   | No        |

## Reporting a vulnerability

Please do not open a public issue, discussion or pull request for a security problem.

Report it privately through GitHub:
[Report a vulnerability](https://github.com/mtubis/html-translator-laravel/security/advisories/new)
(the **Security** tab of the repository, then **Report a vulnerability**).

Include the affected version, the steps to reproduce and the impact you expect. Never include real API keys or other
secrets; use placeholders.

You can expect an acknowledgement within 7 days. Once the report is confirmed, a fix is released as a patch version and
a GitHub security advisory is published, crediting you unless you prefer otherwise.

## Scope

This package is the Laravel integration of the core [`mtubis/html-translator`](https://github.com/mtubis/html-translator):
configuration, facade, Artisan commands and the Laravel HTTP client, cache and log adapters. Problems in translation
logic or provider clients belong to the core repository, but reporting here is fine; the report is forwarded.
