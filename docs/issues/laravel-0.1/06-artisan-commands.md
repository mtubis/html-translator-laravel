# Artisan: html-translator:translate, html-translator:check and the about section

## Context
ARCHITECTURE §4, ADR-0007. Commands are thin: all logic lives in the manager and the factories.

## Scope
- `html-translator:translate {input} {--to=*} {--from=} {--provider=} {--profile=} {--output=} {--fallback=}`
  - `input` is a file path or `-` for STDIN; several `--to` values write one file per locale (`page.html` → `page.de.html`), or print to STDOUT for a single target without `--output`.
  - Progress bar per batch (`onProgress`); after each document a short summary: units, batches, cache hits, retries, tokens, issues; exit code 1 when a result is incomplete.
- `html-translator:check {--provider=*}` — resolves every provider entry and profile, reports all configuration problems at once (no network calls); exit code 1 on errors. `--ping` sends one tiny translation per provider.
- `AboutCommand::add('HTML Translator', …)`: package and core versions, default provider and driver, model, cache store, log channel — never keys.

## Acceptance criteria
- [ ] Feature tests with the `pseudo` provider for file, STDIN, multiple targets, `--output`, incomplete result exit code, unknown provider/profile messages.
- [ ] `check` lists two broken entries in one run; a test asserts no secret appears in its output.
- [ ] `php artisan about` shows the section (test via `Artisan::call('about', ['--json' => true])`).
- [ ] `composer check` green.
