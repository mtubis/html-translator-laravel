# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Project skeleton: architecture, ADRs 0001–0008, configuration specification, service provider that merges and publishes the configuration.
- Configuration validation (ADR-0007): profiles, options and `html.*` are checked when built; errors are an `InvalidConfigurationException` naming the full key (`html-translator.profiles.docs.term_policy: expected one of …`), suggest the closest key or value for typos and never quote secrets.
- `LaravelPsr18Client`: model calls go through Laravel's HTTP client (ADR-0004, accepted), so `Http::fake()`, Telescope and Laravel's HTTP events see them; connection failures become a PSR-18 `NetworkException` with credentials redacted.
- Provider drivers: `openai`, `gemini`, `ollama`, `vllm`, `lm_studio`, `openai_compatible`, `anthropic` and `pseudo` entries build the core providers; missing keys name the env variable to set, `http.retry` maps to the core retry policy. `laravel_ai` is reserved for 0.3.
- `HtmlTranslation` facade and `HtmlTranslationManager` (also injectable as `Contracts\TranslatesHtml`): `translate($html, 'de')`, the immutable `PendingTranslation` (`provider()`, `profile()`, `from()`, `fallback()`, `maxCorrectiveRetries()`, `batchSize()`, `tokenBudget()`, `onProgress()`, `withOptions()`) and `translator()` for the core translator, memoized per provider.
- Cache through a Laravel cache store (`cache.*`, ADR-0005) and logging to a Laravel log channel (`log_channel`); the HTTP client, PSR-17 factories, cache pool and logger can be replaced by binding `HtmlTranslatorServiceProvider::HTTP_CLIENT`, `REQUEST_FACTORY`, `STREAM_FACTORY`, `CACHE` and `LOGGER`.
- `pseudo` provider options `corrupt_every` and `corrupt_retries` for testing fallbacks without a model.
- `HtmlTranslation::fake()` for application tests: no network or configuration, pseudo-localized or canned results (`fake(['de' => fn ($html) => …])`), `failWith()` for error handling, `recorded()` and the assertions `assertTranslated()`, `assertTranslatedTo()`, `assertNotTranslated()`, `assertTranslatedTimes()`, `assertNothingTranslated()`. The facade now resolves `Contracts\TranslatesHtml`; inject the contract to stay fakeable.
- `php artisan html-translator:translate page.html --to=de --to=fr` (file or `-` for STDIN; `--from`, `--provider`, `--profile`, `--fallback`, `--output` with `{locale}`): HTML to STDOUT or files, progress and a summary per locale on STDERR, exit code 1 when a result is incomplete.
- `php artisan html-translator:check [--provider=…] [--ping]`: lists every configuration problem of the settings, providers, profiles and options in one run, without secrets.
- An "Html Translator" section in `php artisan about`: versions, default provider, driver, model, cache store and log channel.
