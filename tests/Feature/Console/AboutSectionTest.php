<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature\Console;

use Composer\InstalledVersions;
use HtmlTranslator\Laravel\HtmlTranslatorServiceProvider;
use HtmlTranslator\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(HtmlTranslatorServiceProvider::class)]
final class AboutSectionTest extends TestCase
{
    private const string KEY = 'sk-test-SECRET-0123456789';

    #[Test]
    public function it_adds_a_section_without_secrets_to_artisan_about(): void
    {
        config()->set('html-translator.default', 'claude');
        config()->set('html-translator.providers.claude', ['driver' => 'anthropic', 'api_key' => self::KEY, 'model' => 'claude-test', 'extra_headers' => ['X-Gateway' => self::KEY]]);
        config()->set('html-translator.cache.store', 'array');
        config()->set('html-translator.log_channel', 'stack');

        Artisan::call('about', ['--json' => true]);
        $output = Artisan::output();
        $about = json_decode($output, true, 16, \JSON_THROW_ON_ERROR);

        self::assertIsArray($about);
        self::assertSame([
            'version' => InstalledVersions::getPrettyVersion('mtubis/html-translator-laravel'),
            'core_version' => InstalledVersions::getPrettyVersion('mtubis/html-translator'),
            'default_provider' => 'claude',
            'driver' => 'anthropic',
            'model' => 'claude-test',
            'cache' => 'array',
            'log_channel' => 'stack',
        ], $about['html_translator'] ?? null);
        self::assertStringNotContainsString(self::KEY, $output);
    }

    #[Test]
    public function it_shows_defaults_and_disabled_features(): void
    {
        config()->set('html-translator.default', 'missing');
        config()->set('html-translator.cache.enabled', false);
        config()->set('html-translator.log_channel', false);

        $about = HtmlTranslatorServiceProvider::about($this->app ?? self::fail('No application.'));

        self::assertSame('missing', $about['Default provider']);
        self::assertSame('not set', $about['Driver']);
        self::assertSame('not set', $about['Model']);
        self::assertSame('disabled', $about['Cache']);
        self::assertSame('none', $about['Log channel']);
    }

    #[Test]
    public function it_shows_the_default_store_and_channel(): void
    {
        config()->set('html-translator.cache', ['enabled' => true, 'store' => null]);
        config()->set('html-translator.log_channel');

        $about = HtmlTranslatorServiceProvider::about($this->app ?? self::fail('No application.'));

        self::assertSame('default store', $about['Cache']);
        self::assertSame('default channel', $about['Log channel']);
        self::assertSame('pseudo', $about['Driver']);
    }
}
