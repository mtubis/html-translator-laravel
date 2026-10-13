# 0006. Manager, fluent pending translation, facade and fake

- Status: accepted
- Date: 2026-10-13

## Context
Laravel developers expect: named connections/drivers resolved by a manager (`Mail::mailer('ses')`, `Cache::store('redis')`), fluent immutable builders, a facade, and `Facade::fake()` with assertions. The core `HtmlTranslator` takes a `Locale` and a `TranslationOptions` object — correct but verbose in application code.

## Decision
- `HtmlTranslationManager` resolves and memoizes one core `HtmlTranslator` per provider entry (`translator(?string $name)`), and starts `PendingTranslation`s (`provider()`, `profile()`, `from()`, `fallback()`, …, `translate()`). It does not extend `Illuminate\Support\Manager` (that API is string-keyed `driver()` with magic `__call`, which hides the typed API from PHPStan).
- `PendingTranslation` is immutable (`with…` clones), accepts strings or core value objects, and builds `TranslationOptions` from config defaults plus overrides.
- Facade `HtmlTranslation` (not `HtmlTranslator`, which is the core class name). Its PHPDoc `@method` lines are generated from the manager and checked by a test.
- `HtmlTranslation::fake()` swaps the facade root for `HtmlTranslationFake`, which pseudo-localizes (core `PseudoLocalizationProvider`) and records calls; assertions follow Laravel naming (`assertTranslated`, `assertNotTranslated`, `assertTranslatedTimes`, `assertNothingTranslated`).

## Consequences
- Familiar API; everything is injectable without the facade.
- The fake must keep the same public surface as the manager — enforced by a shared interface `Contracts\TranslatesHtml`.
