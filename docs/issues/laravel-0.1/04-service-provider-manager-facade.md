# Service provider, HtmlTranslationManager, PendingTranslation and facade

## Context
ADR-0005, ADR-0006, ARCHITECTURE §4–§5. The public API in ARCHITECTURE §4 is the specification — plan the exact signatures first and get them approved.

## Scope
- `Contracts\TranslatesHtml` — the surface shared by the manager and the fake.
- `HtmlTranslationManager implements TranslatesHtml`: `translator(?string $provider = null): HtmlTranslator` (memoized), `translate(string $html, Locale|string $target): TranslatedDocument`, and starters `provider()`, `profile()`, `from()`, `fallback()`, `withOptions()`.
- `PendingTranslation` — immutable; `provider()`, `profile(string|TranslationProfile)`, `from(Locale|string|null)`, `fallback(FallbackStrategy)`, `maxCorrectiveRetries()`, `batchSize()`, `tokenBudget()`, `onProgress(Closure)`, `translate()`.
- Service provider: merge and publish config (`--tag=html-translator-config`), bind manager as singleton, PSR-18/17 bindings (overridable), cache pool (`Psr16Adapter` over `cache.store`, prefix, ttl; none when disabled), logger (`log_channel`: null default, false none).
- Facade `Facades\HtmlTranslation` with `@method` PHPDoc and `@see`.

## Acceptance criteria
- [ ] Feature tests with the `pseudo` provider: default provider, named provider, named and object profile, string and `Locale` targets, overrides land in `TranslationOptions` (assert via a recording provider).
- [ ] Translators are memoized per provider; `PendingTranslation` methods never mutate.
- [ ] Cache: second translation of the same HTML has `stats()->cacheHits === units` with the `array` store; disabled cache → no hits.
- [ ] Log channel wiring tested with `Log::fake()` or a spy channel.
- [ ] A test fails when a public manager method is missing from the facade `@method` list.
- [ ] `composer check` green.
