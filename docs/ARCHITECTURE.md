# Architecture

> Status: living document. Decisions that change this file are recorded as ADRs in [`docs/adr/`](adr/).

## 1. Purpose

`mtubis/html-translator-laravel` makes [`mtubis/html-translator`](https://github.com/mtubis/html-translator) (the
**core**) feel native in a Laravel application:

1. **Configuration instead of wiring** — providers, profiles, options, cache and logging in `config/html-translator.php`
   and `.env`; no PSR-18 client, PSR-17 factory or PSR-6 pool to assemble by hand.
2. **Laravel ergonomics** — a facade with a fluent API, named providers and profiles, string locales, an Artisan
   command, `HtmlTranslation::fake()` for application tests.
3. **Laravel infrastructure** — Laravel's HTTP client (so `Http::fake()`, Telescope and Pulse see the calls), cache
   stores, log channels, and later queues, events and Eloquent models.
4. **A foundation for the Filament plugin** — everything a Filament action needs (translate a model attribute into a
   locale, queued, with events) lives here, so the plugin is UI only.

## 2. Non-goals

- **No translation logic.** Segmentation, prompts, validation, retries and fallbacks belong to the core. If the
  package needs a change there, it is an issue in the core repository ([ADR-0002](adr/0002-core-public-api-only.md)).
- No UI (that is the Filament plugin), no HTTP routes or controllers.
- No storage of translations of its own. Eloquent integration writes into the application's own columns, e.g. through
  `spatie/laravel-translatable` (0.2).
- No support for Laravel versions without bug fixes ([ADR-0003](adr/0003-supported-versions.md)).

## 3. Ubiquitous language

Terms of the core (unit, inline tag, profile, term policy, batch, provider, chat model, issue, fallback strategy) keep
their meaning — see the core's ARCHITECTURE §3. Added here:

| Term | Meaning |
|---|---|
| **Provider entry** | A named entry under `providers` in the config (`openai`, `anthropic`, `docs-cheap`, …). |
| **Driver** | The `driver` key of a provider entry: which core adapter and preset it maps to. |
| **Profile entry** | A named entry under `profiles`; maps to a core `TranslationProfile`. |
| **Translator** | A configured core `HtmlTranslator` for one provider entry, built and memoized by the manager. |
| **Manager** (`HtmlTranslationManager`) | Resolves translators by provider name and starts pending translations. |
| **Pending translation** (`PendingTranslation`) | Immutable fluent builder: provider, profile, source, options → `translate()`. |

## 4. Public API

```php
use HtmlTranslator\Laravel\Facades\HtmlTranslation;

// default provider, default profile, model detects the source language
$result = HtmlTranslation::translate($html, 'pl');               // HtmlTranslator\TranslatedDocument

$result = HtmlTranslation::provider('anthropic')
    ->profile('docs')                                             // a profile entry …
    ->from('en')
    ->fallback(FallbackStrategy::TextNodesOnly)
    ->onProgress(fn (Progress $p) => $bar->setProgress($p->unitsDone))
    ->translate($html, 'de');

HtmlTranslation::profile(TranslationProfile::legal()->withFormality(Formality::Formal)) // … or a core profile object
    ->translate($html, 'fr');

HtmlTranslation::withOptions(new TranslationOptions(batchSize: 5)) // core options as they are, instead of config + overrides
    ->provider('openai')
    ->translate($html, 'de');

HtmlTranslation::translator('ollama');                            // the underlying core HtmlTranslator
```

```bash
php artisan html-translator:translate page.html --to=de --from=en --provider=anthropic --profile=docs --output=page.de.html
```

```php
// in an application's test
HtmlTranslation::fake();
// … code under test …
HtmlTranslation::assertTranslated(fn (RecordedTranslation $t) => $t->target === 'de' && str_contains($t->html, 'Pricing'));
HtmlTranslation::assertNothingTranslated();
```

Rules:

- The result is the core's `TranslatedDocument` — not wrapped. Callers check `isComplete()` / `issues()` exactly as in
  the core docs.
- Locales are accepted as strings or core `Locale` objects. Profiles as entry names or core `TranslationProfile`s.
- Every exception thrown by the package implements the core's `HtmlTranslator\Exception\TranslatorException`, so one
  `catch` covers core and package. Configuration errors are `InvalidConfigurationException` and name the config key.
- The facade resolves `Contracts\TranslatesHtml`: the manager, or the fake after `HtmlTranslation::fake()`. Inject the
  contract to stay fakeable; code that type-hints `HtmlTranslationManager` always gets the real translator.

## 5. Components

```
config/html-translator.php
        │
        ▼
 HtmlTranslatorServiceProvider ── registers singletons, publishes config, Artisan command, `about` section
        │
        ▼
 HtmlTranslationManager ──────────── translator(name) memoized ──► HtmlTranslator (core)
   │  provider()/profile()/from()…  ──► PendingTranslation ──► translate()
   │
   ├── Config\ProviderFactory     provider entry  → TranslationProviderInterface (LlmTranslationProvider + chat model, or Pseudo)
   │     └── Config\ChatModelFactory   driver → OpenAiCompatibleConfig / AnthropicConfig → chat model;
   │                                   static retrier(): http.retry → core Retrier
   ├── Config\ProfileFactory      profile entry   → TranslationProfile
   ├── Config\OptionsFactory      options + overrides → TranslationOptions
   ├── Config\HtmlRulesFactory    html.*          → SkipRules, TranslatableAttributes
   ├── Http\LaravelPsr18Client    PSR-18 over Illuminate\Http\Client\Factory (ADR-0004)
   ├── Cache: Symfony Psr16Adapter over a Laravel cache store (ADR-0005)
   └── Log: a Laravel log channel (PSR-3)

 Testing\HtmlTranslationFake ── swaps the manager behind the facade; records translations, pseudo-localizes
```

Dependency rules:

- `Config\*` factories are pure: they get arrays and return core objects. They do not call `config()`, facades or the
  container. Only the service provider and the manager read configuration.
- Nothing outside `Http\` knows about Laravel's HTTP client; nothing outside the service provider knows about cache
  stores and log channels.
- Package code never uses facades internally (they are for application code); it receives contracts through the
  constructor.
- Only the core's public API is used ([ADR-0002](adr/0002-core-public-api-only.md)).

## 6. Package layout

```
config/html-translator.php
src/
  HtmlTranslatorServiceProvider.php
  HtmlTranslationManager.php   PendingTranslation.php
  Facades/HtmlTranslation.php
  Config/                      ProviderFactory, ChatModelFactory, ProfileFactory, OptionsFactory, HtmlRulesFactory, ConfigReader
  Http/                        LaravelPsr18Client
  Console/                     TranslateCommand
  Testing/                     HtmlTranslationFake, RecordedTranslation
  Exceptions/                  InvalidConfigurationException, NetworkException
  (0.2) Jobs/, Events/, Eloquent/
resources/boost/guidelines/core.blade.php   AI guidelines shipped to applications using Laravel Boost
tests/
  TestCase.php (Orchestra Testbench)
  Unit/        factories, no Laravel container needed
  Feature/     service provider, manager, facade, command, fake — inside Testbench
  Integration/ real APIs, group "integration", skipped without keys
workbench/     Testbench workbench app for manual checks (`composer serve`)
```

## 7. Testing strategy

| Layer | What | Runs |
|---|---|---|
| Unit | Config factories with valid and invalid arrays (every error names its key) | always |
| Feature | Service provider, manager, facade, `PendingTranslation`, command, fake, cache and log wiring — in Testbench with the `pseudo` driver and `Http::fake()` | always |
| HTTP | `LaravelPsr18Client` against `Http::fake()`; plus the core's `ChatModelContractTestCase` run with the OpenAI and Anthropic adapters on top of it | always |
| Integration | One real call per driver, `#[Group('integration')]` | manual / nightly |
| Mutation | Infection on `src/` | CI |

`Http::fake()` plus the real core adapters is the most valuable test: it proves the whole chain (config → adapter →
Laravel HTTP → response mapping) without a network.

## 8. Relationship to the core

- Version constraint follows the core's latest minor (`^0.4` now); while the core is 0.x, every core minor needs a
  package release.
- Friction found here (awkward constructors, missing hooks, array validation) is reported to the core **before** its
  1.0 freeze. That feedback loop is one of the reasons to build this package now.
- For cross-repository work, the core source is readable in `vendor/mtubis/html-translator/` (never edited), or a
  local clone can be linked as a Composer path repository (CONTRIBUTING.md).
