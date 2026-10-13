# html-translator for Laravel

Translate HTML in your Laravel application with large language models — **without breaking the markup**.

This package integrates [`mtubis/html-translator`](https://github.com/mtubis/html-translator) with Laravel:
providers and translation profiles in `config/html-translator.php`, a facade with a fluent API, Artisan commands,
Laravel's HTTP client, cache stores and log channels, and `HtmlTranslation::fake()` for your tests. Inline tags stay on
the right words; markup, `<code>`, scripts and `translate="no"` content are never sent to the model — only text and
translatable attributes such as `alt` and `title`.

> **Status:** 0.x — the public API may still change in minor versions; see the [CHANGELOG](CHANGELOG.md).

## Requirements

PHP 8.4+, Laravel 13.

## Installation

```bash
composer require mtubis/html-translator-laravel
php artisan vendor:publish --tag=html-translator-config
```

Pick a provider and set its key and model in `.env`:

```dotenv
HTML_TRANSLATOR_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...
HTML_TRANSLATOR_ANTHROPIC_MODEL=claude-...
```

Then check the configuration — every problem is listed at once, keys are never printed:

```bash
php artisan html-translator:check          # add --ping to send one short test translation per provider
```

## Quick start

<!-- runnable -->
```php
use HtmlTranslator\Laravel\Facades\HtmlTranslation;

$result = HtmlTranslation::translate($html, 'de');            // default provider and profile

$result->html();          // valid HTML, markup preserved
$result->isComplete();    // false when some units kept their source text
$result->issues();        // why, one Issue per unit
$result->stats();         // units, batches, tokens, cache hits

$result = HtmlTranslation::provider('anthropic')              // a provider entry
    ->profile('docs')                                         // a profile entry
    ->from('en')                                              // null or omitted: the model detects the language
    ->translate($html, 'pt-BR');

if (! $result->isComplete()) {
    foreach ($result->issues() as $issue) {
        logger()->warning($issue->message, ['unit' => $issue->unitId->toString()]);
    }
}
```

The result is the core `TranslatedDocument`. How the translation works — what is sent to the model, fallbacks,
corrective retries, costs — is documented in the [core library](https://github.com/mtubis/html-translator/blob/main/docs/usage.md).

## Providers

Each entry of `providers` in `config/html-translator.php` is a named provider; several entries may use the same driver
(e.g. a cheap and a strong model). `default` names the entry used when none is given.

| Driver | Required | Optional | Shipped entry reads |
|---|---|---|---|
| `openai` | `api_key`, `model` | `json_mode` | `OPENAI_API_KEY`, `HTML_TRANSLATOR_OPENAI_MODEL` |
| `anthropic` | `api_key`, `model` | `effort`, `base_url`, `refusal_fallbacks`, `betas`, `extra_headers`, `api_version`, `default_max_output_tokens` | `ANTHROPIC_API_KEY`, `HTML_TRANSLATOR_ANTHROPIC_MODEL`, `HTML_TRANSLATOR_ANTHROPIC_EFFORT`, `ANTHROPIC_BASE_URL` |
| `gemini` | `api_key`, `model` | | `GEMINI_API_KEY`, `HTML_TRANSLATOR_GEMINI_MODEL` |
| `ollama` | `model` | `base_url` | `HTML_TRANSLATOR_OLLAMA_MODEL`, `OLLAMA_BASE_URL` |
| `vllm` | `model` | `base_url`, `api_key` | |
| `lm_studio` | `model` | `base_url` | |
| `openai_compatible` | `base_url`, `model` | `name`, `api_key`, `json_mode`, `max_tokens_field`, `extra_headers` | see the commented `together` example |
| `pseudo` | | `corrupt_every`, `corrupt_retries` | no network: pseudo-localization for development and tests |

The drivers map to the core's adapters and presets ([core providers guide](https://github.com/mtubis/html-translator/blob/main/docs/providers.md)).
`laravel_ai` (providers from `config/ai.php`) is planned for 0.3. Unknown keys and wrong values fail with an
`InvalidConfigurationException` that names the full key, e.g.
`html-translator.providers.openai.api_key: is required; the shipped configuration reads it from OPENAI_API_KEY`.

`HtmlTranslation::translator('ollama')` returns the core `HtmlTranslator` of an entry, resolved once.

## Profiles

Profiles steer how text is translated: a preset (`default`, `technical`, `marketing`, `legal`) plus `domain`,
`formality`, `term_policy`, `glossary`, `do_not_translate` and `instructions` overrides. Name an entry of `profiles`,
or pass a core profile:

<!-- runnable -->
```php
use HtmlTranslator\Laravel\Facades\HtmlTranslation;
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\TranslationProfile;

HtmlTranslation::profile('docs')->translate($html, 'de');

HtmlTranslation::profile(TranslationProfile::legal()->withFormality(Formality::Formal))
    ->translate($html, 'fr');
```

Details: [core profiles guide](https://github.com/mtubis/html-translator/blob/main/docs/profiles.md).

## Options

`options` in the config sets the defaults (`source`, `fallback`, `max_corrective_retries`, `batch_size`,
`token_budget`); a pending translation overrides them per call. Every method returns a new pending translation, so one
can be reused:

<!-- runnable -->
```php
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Laravel\Facades\HtmlTranslation;
use HtmlTranslator\Progress;
use HtmlTranslator\TranslationOptions;

$german = HtmlTranslation::from('en')
    ->fallback(FallbackStrategy::TextNodesOnly)   // or KeepOriginal (default), Throw
    ->maxCorrectiveRetries(1)
    ->batchSize(10)
    ->tokenBudget(1500)
    ->onProgress(fn (Progress $progress) => info("{$progress->unitsDone}/{$progress->unitsTotal} units"));

$german->translate($html, 'de');
$german->translate($html, 'de-AT');

// Core options as they are, instead of the configuration (combining them with the methods above is an error):
HtmlTranslation::withOptions(new TranslationOptions(batchSize: 5))->provider('openai')->translate($html, 'de');
```

## Dependency injection

Everything the facade does is available on `HtmlTranslator\Laravel\Contracts\TranslatesHtml`. Inject the contract
(not `HtmlTranslationManager`), so `HtmlTranslation::fake()` also replaces it in your tests:

<!-- runnable -->
```php
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;

final class PageTranslator
{
    public function __construct(private readonly TranslatesHtml $translator) {}

    public function german(string $html): string
    {
        return $this->translator->profile('docs')->translate($html, 'de')->html();
    }
}

$translated = app(PageTranslator::class)->german($html);
```

## Errors

Every exception of the package and the core implements `HtmlTranslator\Exception\TranslatorException`:

<!-- runnable -->
```php
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Exception\TranslatorException;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Laravel\Facades\HtmlTranslation;

try {
    $result = HtmlTranslation::provider('openai')->translate($html, 'de');
} catch (InvalidConfigurationException $exception) {
    report($exception);              // a config problem; $exception->key names it, e.g. html-translator.profiles.docs
} catch (ProviderException $exception) {
    report($exception);              // the model failed after the retries (statusCode, vendorCode)
} catch (TranslatorException $exception) {
    report($exception);              // anything else from the translator
}
```

Configuration is validated when a provider or profile is first used, so an application without keys for an unused
provider still boots. Messages never contain API keys or header values.

## Cache, logging and HTTP

- **Cache** (`cache.*`): translated units are cached in a Laravel cache store (default store unless `cache.store`
  names one), per text, locale pair, profile and model — re-translating an edited page only pays for the changed
  paragraphs. `HTML_TRANSLATOR_CACHE=false` disables it.
- **Logging** (`log_channel`): units that could not be translated are logged as warnings to a channel of
  `config/logging.php`; `null` is the default channel, `false` turns logging off.
- **HTTP**: model calls go through Laravel's HTTP client, so `Http::fake()`, Telescope and Laravel's HTTP events see
  them. They are sent exactly as the core builds them: `Http::globalOptions()` headers and global request middleware do
  not apply. Telescope stores request headers and hides only `Authorization` (the OpenAI-compatible drivers' key) by
  default: hide `x-api-key` (Anthropic), `api-key` and your `extra_headers` with `Telescope::hideRequestHeaders()`. Retries (`http.retry`) are done by the core, not by the HTTP client.
- Rebind `HtmlTranslatorServiceProvider::HTTP_CLIENT`, `CACHE` or `LOGGER` in the container to replace the PSR-18
  client, the PSR-6 pool or the PSR-3 logger.

## Artisan

```bash
php artisan html-translator:translate page.html --to=de                 # HTML to STDOUT, summary to STDERR
php artisan html-translator:translate page.html --to=de --to=fr         # page.de.html, page.fr.html
cat page.html | php artisan html-translator:translate - --to=de --from=en --provider=anthropic --profile=docs \
    --fallback=text_nodes_only --output=page.{locale}.html
php artisan html-translator:check --provider=openai --ping
php artisan about --only=html_translator
```

`translate` exits with 1 when a result is incomplete (`-v` lists the issues), `check` when anything is misconfigured.

## Testing your application

`HtmlTranslation::fake()` replaces the translator with a fake that needs no network and no configuration: it
pseudo-localizes the HTML (or returns canned results) and records every translation for assertions. It replaces what
the facade and an injected `HtmlTranslator\Laravel\Contracts\TranslatesHtml` resolve to, so inject the contract (not
`HtmlTranslationManager`) in code you want to fake.

Code under test:

```php
namespace App\Actions;

use HtmlTranslator\Laravel\Facades\HtmlTranslation;

final class TranslatePage
{
    public function __invoke(string $html, string $locale): string
    {
        return HtmlTranslation::profile('marketing')->from('en')->translate($html, $locale)->html();
    }
}
```

The test methods, in a test class extending your application's `Tests\TestCase`:

```php
use App\Actions\TranslatePage;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Laravel\Facades\HtmlTranslation;
use HtmlTranslator\Laravel\Testing\RecordedTranslation;

public function test_the_page_is_translated_to_german(): void
{
    HtmlTranslation::fake();

    $html = (new TranslatePage())('<h1>Pricing</h1>', 'de');

    HtmlTranslation::assertTranslatedTo('de');
    HtmlTranslation::assertTranslated(fn (RecordedTranslation $t) => $t->profile === 'marketing' && str_contains($t->html, 'Pricing'));
    HtmlTranslation::assertTranslatedTimes(1);
    $this->assertStringNotContainsString('Pricing', $html); // pseudo-localized, markup kept
}

public function test_canned_results_and_failures(): void
{
    HtmlTranslation::fake(['de' => fn (string $html) => '<h1>Preise</h1>']);

    $this->assertSame('<h1>Preise</h1>', (new TranslatePage())('<h1>Pricing</h1>', 'de'));

    HtmlTranslation::fake()->failWith(new ProviderException('The model is overloaded.'));

    $this->expectException(ProviderException::class);
    (new TranslatePage())('<h1>Pricing</h1>', 'de');
}
```

Other assertions: `assertNotTranslated(?Closure)`, `assertNothingTranslated()`; `HtmlTranslation::recorded()` returns
the `RecordedTranslation`s (html, target, source, provider, profile, result).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Design decisions are recorded in [docs/adr](docs/adr/).

## License

MIT
