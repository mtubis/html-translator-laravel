<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature;

use HtmlTranslator\Laravel\HtmlTranslatorServiceProvider;
use HtmlTranslator\Laravel\Tests\TestCase;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(HtmlTranslatorServiceProvider::class)]
final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function it_merges_the_package_configuration(): void
    {
        self::assertSame('pseudo', config('html-translator.default'));
        self::assertIsArray(config('html-translator.providers.pseudo'));
        self::assertSame('pseudo', config('html-translator.providers.pseudo.driver'));
    }

    #[Test]
    public function it_publishes_the_configuration_under_its_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(HtmlTranslatorServiceProvider::class, 'html-translator-config');

        self::assertCount(1, $paths);
        self::assertStringEndsWith('config/html-translator.php', (string) array_key_first($paths));
        self::assertSame($this->app->configPath('html-translator.php'), array_values($paths)[0]);
    }
}
