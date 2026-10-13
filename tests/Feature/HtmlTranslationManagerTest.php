<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature;

use Closure;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Exception\TranslatorException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Laravel\Config\ChatModelFactory;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\HtmlRulesFactory;
use HtmlTranslator\Laravel\Config\OptionsFactory;
use HtmlTranslator\Laravel\Config\ProfileFactory;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Laravel\HtmlTranslationManager;
use HtmlTranslator\Laravel\HtmlTranslatorServiceProvider;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use HtmlTranslator\Laravel\PendingTranslation;
use HtmlTranslator\Laravel\Tests\TestCase;
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Progress;
use HtmlTranslator\TranslationOptions;
use Illuminate\Foundation\Application;
use Illuminate\Log\Logger as IlluminateLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

#[CoversClass(HtmlTranslationManager::class)]
#[CoversClass(PendingTranslation::class)]
#[UsesClass(HtmlTranslatorServiceProvider::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(ProviderFactory::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(ProfileFactory::class)]
#[UsesClass(OptionsFactory::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
#[UsesClass(LaravelPsr18Client::class)]
final class HtmlTranslationManagerTest extends TestCase
{
    private const string HTML = '<p>Hello <b>world</b></p><p>Second paragraph</p>';

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('html-translator.default', 'pseudo');
        $app['config']->set('html-translator.providers.other', ['driver' => 'pseudo']);
        $app['config']->set('html-translator.profiles.docs', ['preset' => 'technical', 'formality' => 'informal']);
    }

    #[Test]
    public function it_translates_with_the_default_provider(): void
    {
        $document = $this->manager()->translate(self::HTML, 'de');

        self::assertTrue($document->isComplete());
        self::assertStringNotContainsString('Hello', $document->html());
        self::assertStringContainsString('<b>', $document->html());
    }

    #[Test]
    public function it_accepts_a_locale_object_as_the_target(): void
    {
        self::assertSame(
            $this->manager()->translate(self::HTML, 'pt-BR')->html(),
            $this->manager()->translate(self::HTML, Locale::fromString('pt-BR'))->html(),
        );
    }

    #[Test]
    public function it_memoizes_one_core_translator_per_provider(): void
    {
        $manager = $this->manager();

        self::assertSame($manager->translator(), $manager->translator('pseudo'));
        self::assertSame($manager->translator('other'), $manager->translator('other'));
        self::assertNotSame($manager->translator('pseudo'), $manager->translator('other'));
    }

    #[Test]
    public function it_translates_with_a_named_provider(): void
    {
        self::assertTrue($this->manager()->provider('other')->translate(self::HTML, 'de')->isComplete());
    }

    #[Test]
    public function it_names_an_undefined_provider(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('html-translator.providers.missing: is not defined; defined: ');

        $this->manager()->provider('missing')->translate(self::HTML, 'de');
    }

    #[Test]
    public function it_names_an_undefined_profile(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('html-translator.profiles.missing: is not defined; defined: default, docs');

        $this->manager()->profile('missing')->translate(self::HTML, 'de');
    }

    #[Test]
    public function it_resolves_the_default_named_and_object_profiles(): void
    {
        $manager = $this->manager();

        self::assertEquals(TranslationProfile::default(), $manager->options(new PendingTranslation($manager))->profile);
        self::assertEquals(TranslationProfile::technical()->withFormality(Formality::Informal), $manager->options($manager->profile('docs'))->profile);

        $legal = TranslationProfile::legal();
        self::assertSame($legal, $manager->options($manager->profile($legal))->profile);
    }

    #[Test]
    public function it_uses_the_configured_default_profile(): void
    {
        config()->set('html-translator.default_profile', 'docs');

        self::assertEquals(TranslationProfile::technical()->withFormality(Formality::Informal), $this->manager()->options(new PendingTranslation($this->manager()))->profile);
    }

    #[Test]
    public function it_builds_the_options_from_the_configuration(): void
    {
        config()->set('html-translator.options', ['source' => 'en', 'fallback' => 'throw', 'max_corrective_retries' => 1, 'batch_size' => 5, 'token_budget' => 500]);
        $manager = $this->manager();

        $options = $manager->options(new PendingTranslation($manager));

        self::assertEquals(Locale::fromString('en'), $options->source);
        self::assertSame(FallbackStrategy::Throw, $options->fallback);
        self::assertSame(1, $options->maxCorrectiveRetries);
        self::assertSame(5, $options->batchSize);
        self::assertSame(500, $options->tokenBudget);
    }

    #[Test]
    public function it_lets_every_override_land_in_the_options(): void
    {
        config()->set('html-translator.options.source', 'en');
        $manager = $this->manager();
        $onProgress = static function (Progress $progress): void {};

        $options = $manager->options(
            $manager->from('fr')
                ->fallback(FallbackStrategy::TextNodesOnly)
                ->maxCorrectiveRetries(0)
                ->batchSize(3)
                ->tokenBudget(900)
                ->onProgress($onProgress),
        );

        self::assertEquals(Locale::fromString('fr'), $options->source);
        self::assertSame(FallbackStrategy::TextNodesOnly, $options->fallback);
        self::assertSame(0, $options->maxCorrectiveRetries);
        self::assertSame(3, $options->batchSize);
        self::assertSame(900, $options->tokenBudget);
        self::assertSame($onProgress, $options->onProgress);
    }

    #[Test]
    public function it_overrides_a_configured_source_with_detection(): void
    {
        config()->set('html-translator.options.source', 'en');
        $manager = $this->manager();

        self::assertNull($manager->options($manager->from(null))->source);
        self::assertEquals(Locale::fromString('de'), $manager->options($manager->from(Locale::fromString('de')))->source);
    }

    #[Test]
    public function it_starts_pending_translations_from_every_starter(): void
    {
        $manager = $this->manager();
        $options = new TranslationOptions(batchSize: 7);

        self::assertSame('other', $manager->provider('other')->providerName);
        self::assertSame('docs', $manager->profile('docs')->profile);
        self::assertSame('en', $manager->from('en')->source);
        self::assertSame(FallbackStrategy::Throw, $manager->fallback(FallbackStrategy::Throw)->fallback);
        self::assertSame($options, $manager->options($manager->withOptions($options)));
    }

    #[Test]
    public function it_uses_given_core_options_instead_of_the_configuration(): void
    {
        config()->set('html-translator.options.batch_size', 5);
        $manager = $this->manager();
        $options = new TranslationOptions(batchSize: 7);

        self::assertSame($options, $manager->options($manager->provider('other')->withOptions($options)));
    }

    /**
     * @return iterable<string, array{Closure(PendingTranslation): PendingTranslation}>
     */
    public static function overridesBesideCoreOptions(): iterable
    {
        yield 'from' => [static fn(PendingTranslation $p): PendingTranslation => $p->from(null)];
        yield 'profile' => [static fn(PendingTranslation $p): PendingTranslation => $p->profile('docs')];
        yield 'fallback' => [static fn(PendingTranslation $p): PendingTranslation => $p->fallback(FallbackStrategy::Throw)];
        yield 'retries' => [static fn(PendingTranslation $p): PendingTranslation => $p->maxCorrectiveRetries(0)];
        yield 'batch size' => [static fn(PendingTranslation $p): PendingTranslation => $p->batchSize(2)];
        yield 'token budget' => [static fn(PendingTranslation $p): PendingTranslation => $p->tokenBudget(10)];
        yield 'progress' => [static fn(PendingTranslation $p): PendingTranslation => $p->onProgress(static function (Progress $progress): void {})];
    }

    /**
     * withOptions() replaces every override: combining them would silently drop one side.
     *
     * @param Closure(PendingTranslation): PendingTranslation $override
     */
    #[Test]
    #[DataProvider('overridesBesideCoreOptions')]
    public function it_rejects_overrides_combined_with_core_options(Closure $override): void
    {
        $manager = $this->manager();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('withOptions() replaces the configured options and every override');

        $manager->options($override($manager->withOptions(new TranslationOptions())));
    }

    /**
     * The overrides reach the core: with a provider that breaks every unit, the fallback decides the outcome.
     */
    #[Test]
    public function it_passes_the_overrides_to_the_core_translator(): void
    {
        config()->set('html-translator.providers.broken', ['driver' => 'pseudo', 'corrupt_every' => 1, 'corrupt_retries' => true]);
        $manager = $this->manager();

        self::assertFalse($manager->provider('broken')->translate(self::HTML, 'de')->isComplete());

        $this->expectException(TranslatorException::class);

        $manager->provider('broken')->fallback(FallbackStrategy::Throw)->translate(self::HTML, 'de');
    }

    #[Test]
    public function it_keeps_api_keys_out_of_dumps(): void
    {
        config()->set('html-translator.providers.openai', ['driver' => 'openai', 'api_key' => 'sk-secret-123', 'model' => 'gpt-test']);
        $manager = $this->manager();
        $manager->translator('openai');
        $pending = $manager->provider('openai');

        $dumper = new CliDumper();
        $dumped = (string) $dumper->dump(new VarCloner()->cloneVar($pending), true);

        self::assertStringNotContainsString('sk-secret-123', print_r($manager, true));
        self::assertStringNotContainsString('sk-secret-123', print_r($pending, true));
        self::assertStringNotContainsString('sk-secret-123', $dumped);
        self::assertStringContainsString('openai', $dumped);
    }

    #[Test]
    public function it_shows_only_names_in_dumps(): void
    {
        $manager = $this->manager();
        $manager->translator('other');

        self::assertSame(
            [
                'default' => 'pseudo',
                'providers' => ['openai', 'anthropic', 'gemini', 'ollama', 'pseudo', 'other'],
                'translators' => ['other'],
                'cache' => Psr16Adapter::class,
                'logger' => IlluminateLogger::class,
            ],
            $manager->__debugInfo(),
        );
    }

    /**
     * ADR-0007: configuration is validated when a provider is used, so an unused, incomplete entry does no harm.
     */
    #[Test]
    public function it_works_while_an_unused_provider_lacks_its_key(): void
    {
        config()->set('html-translator.providers.openai', ['driver' => 'openai', 'api_key' => null, 'model' => null]);

        self::assertTrue($this->manager()->translate(self::HTML, 'de')->isComplete());
    }

    #[Test]
    public function it_reports_progress_for_every_batch(): void
    {
        $batches = [];

        $this->manager()
            ->provider('pseudo')
            ->batchSize(1)
            ->onProgress(static function (Progress $progress) use (&$batches): void {
                $batches[] = $progress->batchesDone;
            })
            ->translate(self::HTML, 'de');

        self::assertSame([1, 2], $batches);
    }

    #[Test]
    public function it_never_mutates_a_pending_translation(): void
    {
        $manager = $this->manager();
        $pending = new PendingTranslation($manager);
        $before = clone $pending;

        $changed = [
            $pending->provider('other'),
            $pending->profile('docs'),
            $pending->from('en'),
            $pending->from(null),
            $pending->fallback(FallbackStrategy::Throw),
            $pending->maxCorrectiveRetries(1),
            $pending->batchSize(1),
            $pending->tokenBudget(1),
            $pending->onProgress(static function (Progress $progress): void {}),
            $pending->withOptions(new TranslationOptions()),
        ];

        self::assertEquals($before, $pending);
        foreach ($changed as $clone) {
            self::assertNotSame($pending, $clone);
            self::assertNotEquals($pending, $clone);
        }
    }

    #[Test]
    public function it_is_bound_under_the_contract(): void
    {
        self::assertSame($this->manager(), $this->app?->make(TranslatesHtml::class));
    }

    private function manager(): HtmlTranslationManager
    {
        $manager = $this->app?->make(HtmlTranslationManager::class);
        self::assertInstanceOf(HtmlTranslationManager::class, $manager);

        return $manager;
    }
}
