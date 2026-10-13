---
name: add-provider-driver
description: Use when adding or changing a provider driver in config/html-translator.php (openai, anthropic, ollama, openai_compatible, laravel_ai, …) or when the core adds a new chat model adapter or preset that the Laravel package should expose.
---

# Add or change a provider driver

A driver maps a config entry to a core chat model or provider. The package never implements vendor HTTP itself (ADR-0002): if the vendor needs a new adapter, that is a core issue first (core skill `add-translation-provider`).

1. Read the core adapter and its config class in `vendor/mtubis/html-translator/src/Provider/Llm/Bridge/` and the matching section of `vendor/mtubis/html-translator/docs/providers.md`. List every constructor/preset parameter, its type and default.
2. Design the config entry: snake_case keys mirroring the core parameter names (`base_url`, `max_tokens_field`, `refusal_fallbacks`), env variables for secrets and models, sensible defaults matching the core. Add the entry (or a commented example) to `config/html-translator.php` with a one-line comment per non-obvious key.
3. Unit tests first in `tests/Unit/Config/ChatModelFactoryTest.php`: valid entry → expected core config values; missing required keys name the env variable; wrong types and unknown enum values name the full key; secrets never appear in messages.
4. Implement the mapping in `Config\ChatModelFactory` (and `ProviderFactory` if it is not an LLM driver). Read values only through `ConfigReader`. Re-throw core `InvalidArgumentException` as `InvalidConfigurationException` with the key.
5. Feature test with `Http::fake()` and a canned vendor response (copy the shape from the core's `tests/Unit/Provider/Llm/Bridge/*/Fixtures/success.json`): `HtmlTranslation::provider('<name>')->translate('<p>Hello</p>', 'de')` produces translated HTML; `Http::assertSent()` checks URL and auth header name (not value).
6. Integration test in `tests/Integration/` with `#[Group('integration')]`, skipped without the env variable; add the variable to `.env.dist`.
7. Update the README providers table and `html-translator:check` expectations. `composer check` green.
