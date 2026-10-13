# HtmlTranslation::fake() and assertions

## Context
ADR-0006. Application developers must be able to test code that translates HTML without network and without knowing the core.

## Scope
- `Testing\HtmlTranslationFake implements TranslatesHtml` — pseudo-localizes with the core `PseudoLocalizationProvider`, records every call as `Testing\RecordedTranslation` (html, target, source, provider, profile name or fingerprint, result).
- `HtmlTranslation::fake()` swaps the facade root; `fake()` returns the fake for chaining.
- Optional canned results: `HtmlTranslation::fake(['de' => fn (string $html) => '<p>Hallo</p>'])` or a failing provider for testing error handling (`HtmlTranslation::fake()->failWith(ProviderException …)`).
- Assertions: `assertTranslated(?Closure)`, `assertTranslatedTo(string $locale)`, `assertNotTranslated(?Closure)`, `assertTranslatedTimes(int)`, `assertNothingTranslated()` with PHPUnit-friendly failure messages.

## Acceptance criteria
- [ ] Tests for every assertion, both passing and failing paths (assert the failure message).
- [ ] The fake and the manager satisfy the same interface; a pending translation started on the fake records profile/provider/source.
- [ ] README section "Testing your application" with a complete example (tested as a snippet like in the core).
- [ ] `composer check` green.
