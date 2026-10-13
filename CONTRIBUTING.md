# Contributing

1. Read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and the [ADRs](docs/adr/). Translation behaviour belongs to the
   [core library](https://github.com/mtubis/html-translator) — report it there.
2. `composer install`, then make your change with tests.
3. `composer check` must pass (Pint, Rector, PHPStan with Larastan at level max, PHPUnit).
4. Use [Conventional Commits](https://www.conventionalcommits.org/).

## Trying changes in a Laravel app

`composer serve` starts the Testbench workbench application with the package registered.

## Working on the core at the same time

To test against a local clone of the core, temporarily add a path repository to `composer.json` and revert it
before committing:

```json
"repositories": [{ "type": "path", "url": "../html-translator", "options": { "symlink": true } }]
```

then `composer require mtubis/html-translator:"*@dev"`.

## Integration tests

Real API calls are in the `integration` group and are not run by default (they cost a few tokens). Copy `.env.dist`
to `.env`, add keys and models, load them into the environment and run:

```bash
set -a; . ./.env; set +a
composer test:integration
```

A driver whose variables are missing is skipped.
