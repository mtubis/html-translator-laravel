# README, Boost guidelines, integration tests and release 0.1.0

## Context
ARCHITECTURE §7. The core tests its documentation snippets (core `tests/Documentation`); do the same here.

## Scope
- README: install, minimal `.env`, quick start, providers table (driver → keys), profiles, options, cache, logging, Artisan, testing with the fake, errors, link to the core docs for behaviour. Every PHP snippet runs in a test (pseudo provider).
- `resources/boost/guidelines/core.blade.php` — concise AI guidelines for applications using Laravel Boost: what the package does, facade usage, checking `isComplete()`/`issues()`, `fake()` in tests, never hard-coding keys. Optionally `resources/boost/skills/html-translator/SKILL.md`.
- Integration tests (`#[Group('integration')]`), one per LLM driver with real keys from `.env`, skipped without them; `.env.dist`.
- CHANGELOG `0.1.0`, `docs/RELEASING.md` (adapted from the core), Packagist submission.

## Acceptance criteria
- [ ] Snippet test covers every README code block marked as runnable.
- [ ] Boost guideline renders (Blade compiles, `@verbatim` around code) — test it.
- [ ] `composer test:integration` passes locally against at least OpenAI-compatible and Anthropic.
- [ ] Tag `v0.1.0` published and visible on Packagist.
