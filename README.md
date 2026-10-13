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

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Design decisions are recorded in [docs/adr](docs/adr/).

## License

MIT
