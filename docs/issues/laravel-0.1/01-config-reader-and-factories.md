# ConfigReader, InvalidConfigurationException and the profile, options and HTML rules factories

## Context
ADR-0007, ARCHITECTURE §5. `config/html-translator.php` is the specification. Factories are pure: arrays in, core objects out — no `config()`, no container, no facades.

## Scope
- `Exceptions\InvalidConfigurationException` implementing core `HtmlTranslator\Exception\TranslatorException`; carries the full key.
- `Config\ConfigReader` — typed accessors over a nested array with a key prefix: `string()`, `nullableString()`, `int()`, `positiveInt()`, `bool()`, `stringList()`, `stringMap()`, `enum(BackedEnum class)`, `oneOf(list)`, `secret()` (never quoted in messages). Accepts what `env()` produces where sensible (`"30"` for an int, `"true"`/`"false"` for a bool) — document exactly which coercions.
- `Config\ProfileFactory::fromArray(string $name, array $entry): TranslationProfile` — `preset` (default, technical, marketing, legal), then `domain`, `formality`, `term_policy`, `glossary`, `do_not_translate`, `instructions` as overrides.
- `Config\OptionsFactory` — `TranslationOptions` from `options` plus overrides (source, fallback, retries, batch size, token budget, profile, onProgress). `FallbackStrategy` is a pure enum: map `keep_original` / `text_nodes_only` / `throw` explicitly.
- `Config\HtmlRulesFactory` — `SkipRules` and `TranslatableAttributes` from `html.*`.
- Core `InvalidArgumentException`s are re-thrown as `InvalidConfigurationException` with the key and the core message.

## Acceptance criteria
- [ ] Unit tests (no Testbench) for every key: valid values, every invalid type, unknown enum values, unknown keys in an entry (error, with a "did you mean" suggestion when the Levenshtein distance is ≤ 2).
- [ ] Every message names the full key (`html-translator.profiles.docs.term_policy`), and no message contains a secret (test with a fake key).
- [ ] The commented `docs` profile in `config/html-translator.php` builds without errors (test loads the example).
- [ ] `composer check` green; Infection MSI for `src/Config` ≥ 90 %.
