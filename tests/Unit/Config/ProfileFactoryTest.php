<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Config;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\ProfileFactory;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\TermPolicy;
use HtmlTranslator\Profile\TranslationProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfileFactory::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class ProfileFactoryTest extends TestCase
{
    use CatchesConfigurationErrors;

    /**
     * @return iterable<string, array{string, TranslationProfile}>
     */
    public static function presets(): iterable
    {
        yield 'default' => ['default', TranslationProfile::default()];
        yield 'technical' => ['technical', TranslationProfile::technical()];
        yield 'marketing' => ['marketing', TranslationProfile::marketing()];
        yield 'legal' => ['legal', TranslationProfile::legal()];
    }

    #[Test]
    #[DataProvider('presets')]
    public function it_starts_from_the_preset(string $preset, TranslationProfile $expected): void
    {
        self::assertEquals($expected, ProfileFactory::fromArray('docs', ['preset' => $preset]));
    }

    #[Test]
    public function it_uses_the_default_preset_when_none_is_given(): void
    {
        self::assertEquals(TranslationProfile::default(), ProfileFactory::fromArray('docs', []));
        self::assertEquals(TranslationProfile::default(), ProfileFactory::fromArray('docs', ['preset' => null]));
    }

    #[Test]
    public function it_names_the_key_of_an_unknown_preset(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey(
            'html-translator.profiles.blog.preset',
            'expected one of default, technical, marketing, legal, got "legl"; did you mean "legal"?',
        ));

        ProfileFactory::fromArray('blog', ['preset' => 'legl']);
    }

    #[Test]
    public function it_applies_every_override_on_top_of_the_preset(): void
    {
        $profile = ProfileFactory::fromArray('docs', [
            'preset' => 'technical',
            'domain' => 'Developer documentation',
            'formality' => 'informal',
            'term_policy' => 'keep_original',
            'glossary' => ['pull request' => 'Pull-Request'],
            'do_not_translate' => ['Laravel', 'Eloquent'],
            'instructions' => 'Keep it short.',
        ]);

        $expected = TranslationProfile::technical()
            ->withDomain('Developer documentation')
            ->withFormality(Formality::Informal)
            ->withTermPolicy(TermPolicy::KeepOriginal)
            ->withGlossary(Glossary::fromArray(['pull request' => 'Pull-Request']))
            ->withDoNotTranslate('Laravel', 'Eloquent')
            ->withInstructions('Keep it short.');

        self::assertEquals($expected, $profile);
    }

    #[Test]
    public function it_keeps_the_preset_values_for_overrides_that_are_not_set(): void
    {
        $profile = ProfileFactory::fromArray('ads', [
            'preset' => 'legal',
            'domain' => null,
            'formality' => null,
            'term_policy' => null,
            'glossary' => null,
            'do_not_translate' => null,
            'instructions' => '',
        ]);

        self::assertEquals(TranslationProfile::legal(), $profile);
    }

    #[Test]
    public function it_keeps_an_empty_glossary_and_term_list(): void
    {
        self::assertEquals(
            TranslationProfile::default(),
            ProfileFactory::fromArray('docs', ['glossary' => [], 'do_not_translate' => []]),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'domain type' => [['domain' => 5], 'domain', 'expected a string or null, got 5'];
        yield 'formality value' => [
            ['formality' => 'casual'],
            'formality',
            'expected one of default, formal, informal, got "casual"',
        ];
        yield 'term policy value' => [
            ['term_policy' => 'keep'],
            'term_policy',
            'expected one of translate_all, prefer_original_technical_terms, keep_original, got "keep"',
        ];
        yield 'glossary type' => [['glossary' => 'a=b'], 'glossary', 'expected a map of strings to strings, got "a=b"'];
        yield 'glossary list' => [['glossary' => ['a']], 'glossary', 'expected a map of strings to strings, got a list'];
        yield 'do not translate type' => [['do_not_translate' => 'Laravel'], 'do_not_translate', 'expected a list of strings, got "Laravel"'];
        yield 'instructions type' => [['instructions' => ['a']], 'instructions', 'expected a string or null, got array'];
        yield 'preset type' => [['preset' => 1], 'preset', 'expected one of default, technical, marketing, legal, got 1'];
        yield 'formality type' => [['formality' => true], 'formality', 'expected one of default, formal, informal, got true'];
        yield 'term policy type' => [
            ['term_policy' => ['keep_original']],
            'term_policy',
            'expected one of translate_all, prefer_original_technical_terms, keep_original, got array',
        ];
        yield 'glossary value type' => [['glossary' => ['API' => 1]], 'glossary', 'the value for "API" must be a non-empty string, got 1'];
        yield 'do not translate item type' => [['do_not_translate' => ['Laravel', 5]], 'do_not_translate', 'item 1 must be a non-empty string, got 5'];
        yield 'unknown key' => [['formalty' => 'formal'], 'formalty', 'unknown key; did you mean "formality"?'];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('invalidEntries')]
    public function it_names_the_key_of_an_invalid_value(array $entry, string $key, string $problem): void
    {
        $exception = $this->configurationError(static fn(): TranslationProfile => ProfileFactory::fromArray('docs', $entry));

        self::assertSame('html-translator.profiles.docs.' . $key . ': ' . $problem, $exception->getMessage());
        self::assertSame('html-translator.profiles.docs.' . $key, $exception->key);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function entriesRejectedByTheCore(): iterable
    {
        yield 'domain too long' => [['domain' => str_repeat('a', 201)], 'domain'];
        yield 'multi-line domain' => [['domain' => "a\nb"], 'domain'];
        yield 'instructions too long' => [['instructions' => str_repeat('a', 2001)], 'instructions'];
        yield 'duplicate glossary term' => [['glossary' => ['API' => 'a', 'api' => 'b']], 'glossary'];
        yield 'duplicate do-not-translate term' => [['do_not_translate' => ['Laravel', 'laravel']], 'do_not_translate'];
        yield 'term in glossary and do-not-translate' => [
            ['glossary' => ['Laravel' => 'Laravel'], 'do_not_translate' => ['Laravel']],
            'do_not_translate',
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('entriesRejectedByTheCore')]
    public function it_reports_core_validation_errors_under_the_key(array $entry, string $key): void
    {
        try {
            ProfileFactory::fromArray('docs', $entry);
            self::fail('Expected an InvalidConfigurationException.');
        } catch (InvalidConfigurationException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(InvalidArgumentException::class, $previous);
            self::assertSame('html-translator.profiles.docs.' . $key, $exception->key);
            self::assertSame('html-translator.profiles.docs.' . $key . ': ' . $previous->getMessage(), $exception->getMessage());
        }
    }
}
