<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the package. This skeleton only merges and publishes the configuration; bindings for the manager,
 * HTTP client, cache pool and logger follow in issue "Service provider, HtmlTranslationManager, PendingTranslation
 * and facade" (ARCHITECTURE §5).
 */
final class HtmlTranslatorServiceProvider extends ServiceProvider
{
    public const string CONFIG_KEY = 'html-translator';

    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), self::CONFIG_KEY);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [$this->configPath() => $this->app->configPath(self::CONFIG_KEY . '.php')],
                self::CONFIG_KEY . '-config',
            );
        }
    }

    private function configPath(): string
    {
        return __DIR__ . '/../config/' . self::CONFIG_KEY . '.php';
    }
}
