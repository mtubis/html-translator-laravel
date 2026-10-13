# Bootstrap: dev tooling, Testbench workbench, `composer check` green

## Context
The skeleton ships configuration for PHPUnit, Larastan, Pint, Rector, Infection and Testbench, written without being run. `require-dev` is incomplete on purpose: install the current versions. This issue is also the first dry run of the Claude Code workflow in this repository.

## Scope
- `composer require mtubis/html-translator:^0.4 illuminate/support:^13.0 illuminate/http:^13.0 illuminate/console:^13.0 illuminate/cache:^13.0 illuminate/log:^13.0 symfony/cache guzzlehttp/guzzle guzzlehttp/psr7` (check the exact components the code needs; prefer `illuminate/*` over `laravel/framework` in `require`).
- `composer require --dev orchestra/testbench:^11 larastan/larastan:^3 laravel/pint rector/rector infection/infection phpunit/phpunit phpstan/phpstan-strict-rules phpstan/phpstan-phpunit phpstan/extension-installer`
- `vendor/bin/testbench workbench:install` — keep the generated workbench minimal; add `composer serve`.
- Run `composer check` and `composer infection`, fix configuration problems. Prefer fixing config over relaxing rules.
- Verify the PostToolUse hook (Pint + PHPStan on the edited file).

## Acceptance criteria
- [ ] `composer validate --strict` passes; all constraints are caret constraints.
- [ ] `composer check` green on PHP 8.4; CI green on the first push.
- [ ] `ServiceProviderTest` passes inside Testbench (config merged, publishable).
- [ ] `composer serve` starts the workbench app.
- [ ] Any rule deliberately relaxed is listed in the PR description with a reason.
