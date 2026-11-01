# Roadmap

Each milestone is a GitHub milestone; each bullet is one issue = one Claude Code session = one PR.
Issue bodies are drafted in `docs/issues/<milestone>/` and created with `scripts/create-issues.sh`.

## Laravel 0.1 — configured translation from config, facade and Artisan (v0.1.0)
Goal: `composer require`, set two env vars, `HtmlTranslation::translate($html, 'de')` works; tests use `HtmlTranslation::fake()`.

0. Bootstrap tooling and Testbench workbench, `composer check` green
1. `ConfigReader`, `InvalidConfigurationException`, `ProfileFactory`, `OptionsFactory`, `HtmlRulesFactory`
2. `LaravelPsr18Client` (spike per ADR-0004) with core chat-model contract tests on top of `Http::fake()`
3. `ChatModelFactory` + `ProviderFactory` for every driver (openai, gemini, ollama, vllm, lm_studio, openai_compatible, anthropic, pseudo)
4. Service provider, `HtmlTranslationManager`, `PendingTranslation`, facade; cache and log wiring
5. `HtmlTranslation::fake()` and assertions
6. Artisan: `html-translator:translate`, `html-translator:check`, `about` section
7. README, Boost guidelines, integration tests, release 0.1.0

## Laravel 0.2 — core 1.0 (v0.2.0)
Goal: run on the core's 1.0 API freeze; keep every OpenAI-compatible server usable after the core's new JSON mode defaults.

1. Require core `^1.0`, use the core's public `UntrustedText::quote()` (#19)
2. `json_mode` for the `gemini`, `ollama`, `vllm` and `lm_studio` drivers (#20)

## Laravel 0.3 — queues, events, Eloquent
- Events `HtmlTranslated` (stats, issues, provider, profile) and `HtmlTranslationFailed` — the hook for cost tracking and logging
- Queued job `TranslateHtml` with a destination contract (`TranslationTarget`), `#[Tries]`/`#[Backoff]`, rate limiting middleware per provider
- Eloquent: translate attributes of a model into locales — `spatie/laravel-translatable` as the first target (suggested dependency), `Bus::batch()` across locales
- Artisan: `html-translator:translate-model` for back-filling existing records

## Laravel 0.4 — Laravel AI SDK
- Spike + ADR: `laravel_ai` driver as a core `ChatModelInterface` over `laravel/ai` (reuse providers and keys from `config/ai.php`; map the core's JSON schema to the SDK's structured output or fall back to JSON in the prompt)
- Implementation behind the core's `ChatModelContractTestCase`

## Then
- `html-translator-filament`: Filament v5 plugin — "Translate with AI" actions for rich-editor and translatable fields, a page with translation issues and token usage. Builds on 0.3 (jobs, events, Eloquent targets).
- Follow core releases (constraint `^1.0` since core 1.0), Laravel 14 in Q1 2027.
