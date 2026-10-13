<?php

declare(strict_types=1);

use HtmlTranslator\Laravel\HtmlTranslatorServiceProvider;
use Illuminate\Support\Facades\Route;

// Minimal status page for manual checks with `composer serve`; translation routes follow with the facade.
Route::get('/', static fn(): array => [
    'package' => 'mtubis/html-translator-laravel',
    'provider_registered' => app()->providerIsLoaded(HtmlTranslatorServiceProvider::class),
    'default_provider' => config('html-translator.default'),
]);
