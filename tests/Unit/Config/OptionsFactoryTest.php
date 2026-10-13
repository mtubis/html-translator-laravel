<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Config;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\OptionsFactory;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Progress;
use HtmlTranslator\TranslationOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OptionsFactory::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class OptionsFactoryTest extends TestCase
{
    use CatchesConfigurationErrors;

    #[Test]
    public function it_uses_the_core_defaults_for_an_empty_section(): void
    {
        self::assertEquals(new TranslationOptions(), OptionsFactory::fromArray([]));
    }

    #[Test]
    public function it_reads_every_option(): void
    {
        $options = OptionsFactory::fromArray([
            'source' => 'en',
            'fallback' => 'throw',
            'max_corrective_retries' => 0,
            'batch_size' => '10',
            'token_budget' => 3000,
        ]);

        self::assertEquals(
            new TranslationOptions(
                source: Locale::fromString('en'),
                fallback: FallbackStrategy::Throw,
                maxCorrectiveRetries: 0,
                batchSize: 10,
                tokenBudget: 3000,
            ),
            $options,
        );
    }

    #[Test]
    public function it_lets_the_model_detect_the_source_language_when_none_is_set(): void
    {
        self::assertNull(OptionsFactory::fromArray(['source' => null])->source);
        self::assertNull(OptionsFactory::fromArray(['source' => ''])->source);
    }

    /**
     * @return iterable<string, array{string, FallbackStrategy}>
     */
    public static function fallbacks(): iterable
    {
        yield 'keep_original' => ['keep_original', FallbackStrategy::KeepOriginal];
        yield 'text_nodes_only' => ['text_nodes_only', FallbackStrategy::TextNodesOnly];
        yield 'throw' => ['throw', FallbackStrategy::Throw];
    }

    #[Test]
    #[DataProvider('fallbacks')]
    public function it_maps_the_fallback_strategy(string $value, FallbackStrategy $expected): void
    {
        self::assertSame($expected, OptionsFactory::fromArray(['fallback' => $value])->fallback);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'fallback value' => [
            ['fallback' => 'keep'],
            'fallback',
            'expected one of keep_original, text_nodes_only, throw, got "keep"',
        ];
        yield 'source type' => [['source' => 1], 'source', 'expected a string or null, got 1'];
        yield 'negative retries' => [['max_corrective_retries' => -1], 'max_corrective_retries', 'expected an integer >= 0, got -1'];
        yield 'zero batch size' => [['batch_size' => '0'], 'batch_size', 'expected an integer >= 1, got "0"'];
        yield 'zero token budget' => [['token_budget' => 0], 'token_budget', 'expected an integer >= 1, got 0'];
        yield 'token budget type' => [['token_budget' => 1.5], 'token_budget', 'expected an integer >= 1, got 1.5'];
        yield 'fallback type' => [['fallback' => false], 'fallback', 'expected one of keep_original, text_nodes_only, throw, got false'];
        yield 'retries type' => [['max_corrective_retries' => 'two'], 'max_corrective_retries', 'expected an integer >= 0, got "two"'];
        yield 'batch size type' => [['batch_size' => [20]], 'batch_size', 'expected an integer >= 1, got array'];
        yield 'unknown key' => [['batchsize' => 5], 'batchsize', 'unknown key; did you mean "batch_size"?'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('invalidOptions')]
    public function it_names_the_key_of_an_invalid_option(array $options, string $key, string $problem): void
    {
        $exception = $this->configurationError(static fn(): TranslationOptions => OptionsFactory::fromArray($options));

        self::assertSame('html-translator.options.' . $key . ': ' . $problem, $exception->getMessage());
        self::assertSame('html-translator.options.' . $key, $exception->key);
    }

    #[Test]
    public function it_rejects_an_invalid_source_even_when_overridden(): void
    {
        $exception = $this->configurationError(static fn(): TranslationOptions => OptionsFactory::fromArray(['source' => 'english'], source: 'de'));

        self::assertSame('html-translator.options.source', $exception->key);
    }

    #[Test]
    public function it_rejects_an_invalid_fallback_even_when_overridden(): void
    {
        $exception = $this->configurationError(static fn(): TranslationOptions => OptionsFactory::fromArray(['fallback' => 'keep'], fallback: FallbackStrategy::Throw));

        self::assertSame('html-translator.options.fallback', $exception->key);
    }

    #[Test]
    public function it_rejects_invalid_numbers_even_when_overridden(): void
    {
        $retries = $this->configurationError(static fn(): TranslationOptions => OptionsFactory::fromArray(['max_corrective_retries' => -1], maxCorrectiveRetries: 1));
        $batchSize = $this->configurationError(static fn(): TranslationOptions => OptionsFactory::fromArray(['batch_size' => 'x'], batchSize: 5));
        $tokenBudget = $this->configurationError(static fn(): TranslationOptions => OptionsFactory::fromArray(['token_budget' => 0], tokenBudget: 100));

        self::assertSame('html-translator.options.max_corrective_retries', $retries->key);
        self::assertSame('html-translator.options.batch_size', $batchSize->key);
        self::assertSame('html-translator.options.token_budget', $tokenBudget->key);
    }

    #[Test]
    public function it_reports_an_invalid_source_locale_under_its_key(): void
    {
        try {
            OptionsFactory::fromArray(['source' => 'english']);
            self::fail('Expected an InvalidConfigurationException.');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame('html-translator.options.source', $exception->key);
            self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
            self::assertStringStartsWith('html-translator.options.source: "english" is not a valid locale', $exception->getMessage());
        }
    }

    #[Test]
    public function it_lets_overrides_win_over_the_configuration(): void
    {
        $profile = TranslationProfile::legal();
        $onProgress = static function (Progress $progress): void {};

        $options = OptionsFactory::fromArray(
            ['source' => 'en', 'fallback' => 'throw', 'max_corrective_retries' => 1, 'batch_size' => 5, 'token_budget' => 100],
            profile: $profile,
            source: 'de',
            fallback: FallbackStrategy::TextNodesOnly,
            maxCorrectiveRetries: 3,
            batchSize: 7,
            tokenBudget: 900,
            onProgress: $onProgress,
        );

        self::assertEquals(Locale::fromString('de'), $options->source);
        self::assertSame(FallbackStrategy::TextNodesOnly, $options->fallback);
        self::assertSame(3, $options->maxCorrectiveRetries);
        self::assertSame(7, $options->batchSize);
        self::assertSame(900, $options->tokenBudget);
        self::assertSame($profile, $options->profile);
        self::assertSame($onProgress, $options->onProgress);
    }

    #[Test]
    public function it_accepts_a_locale_object_as_the_source_override(): void
    {
        $source = Locale::fromString('pt-BR');

        self::assertSame($source, OptionsFactory::fromArray(['source' => 'en'], source: $source)->source);
    }

    #[Test]
    public function it_overrides_zero_retries(): void
    {
        self::assertSame(0, OptionsFactory::fromArray(['max_corrective_retries' => 2], maxCorrectiveRetries: 0)->maxCorrectiveRetries);
    }

    #[Test]
    public function it_leaves_invalid_overrides_to_the_core(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be at least 1, got 0.');

        OptionsFactory::fromArray([], batchSize: 0);
    }

    #[Test]
    public function it_leaves_an_invalid_source_override_to_the_core(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OptionsFactory::fromArray([], source: 'not a locale');
    }
}
