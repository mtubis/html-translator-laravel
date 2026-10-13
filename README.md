# html-translator for Laravel

Translate HTML in your Laravel application with large language models — **without breaking the markup**.

This package integrates [`mtubis/html-translator`](https://github.com/mtubis/html-translator) with Laravel:
providers and translation profiles in `config/html-translator.php`, a facade with a fluent API, an Artisan command,
Laravel's HTTP client, cache stores and log channels, and `HtmlTranslation::fake()` for your tests.

> **Status:** early development (0.x). Nothing is released yet — see the [roadmap](docs/ROADMAP.md).

## Requirements

PHP 8.4+, Laravel 13.

## Installation

```bash
composer require mtubis/html-translator-laravel
php artisan vendor:publish --tag=html-translator-config
```

## Planned usage

```php
use HtmlTranslator\Laravel\Facades\HtmlTranslation;

$result = HtmlTranslation::provider('anthropic')->profile('docs')->from('en')->translate($html, 'de');

$result->html();        // valid HTML, markup preserved
$result->isComplete();  // false when some units kept their source text
$result->issues();      // why
```

How the translation itself works — what is sent to the model, fallbacks, profiles, costs — is documented in the
[core library](https://github.com/mtubis/html-translator/blob/main/docs/usage.md).

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
