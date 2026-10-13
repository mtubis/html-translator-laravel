<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Config;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\HtmlRulesFactory;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlRulesFactory::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class HtmlRulesFactoryTest extends TestCase
{
    use CatchesConfigurationErrors;

    #[Test]
    public function it_uses_the_core_defaults_for_an_empty_section(): void
    {
        self::assertEquals(SkipRules::defaults(), HtmlRulesFactory::skipRules([]));
        self::assertEquals(TranslatableAttributes::defaults(), HtmlRulesFactory::translatableAttributes([]));
    }

    #[Test]
    public function it_adds_skipped_elements_and_classes_to_the_defaults(): void
    {
        $rules = HtmlRulesFactory::skipRules(['skip_elements' => ['address', 'x-price'], 'skip_classes' => ['brand']]);

        self::assertEquals(
            SkipRules::defaults()->withElement('address')->withElement('x-price')->withClass('brand'),
            $rules,
        );
    }

    #[Test]
    public function it_adds_translatable_attributes_to_the_defaults(): void
    {
        $attributes = HtmlRulesFactory::translatableAttributes(['translatable_attributes' => ['data-tooltip', 'data-label']]);

        self::assertEquals(
            TranslatableAttributes::defaults()->withAttribute('data-tooltip')->withAttribute('data-label'),
            $attributes,
        );
    }

    #[Test]
    public function it_reads_the_shipped_empty_lists(): void
    {
        $html = ['skip_elements' => [], 'skip_classes' => [], 'translatable_attributes' => []];

        self::assertEquals(SkipRules::defaults(), HtmlRulesFactory::skipRules($html));
        self::assertEquals(TranslatableAttributes::defaults(), HtmlRulesFactory::translatableAttributes($html));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidSkipRules(): iterable
    {
        yield 'elements type' => [['skip_elements' => 'address'], 'skip_elements', 'expected a list of strings, got "address"'];
        yield 'classes item type' => [['skip_classes' => [1]], 'skip_classes', 'item 0 must be a non-empty string, got 1'];
        yield 'elements item type' => [['skip_elements' => ['address', 3]], 'skip_elements', 'item 1 must be a non-empty string, got 3'];
        yield 'classes type' => [['skip_classes' => 'brand'], 'skip_classes', 'expected a list of strings, got "brand"'];
        yield 'unknown key' => [['skip_element' => []], 'skip_element', 'unknown key; did you mean "skip_elements"?'];
    }

    /**
     * @param array<string, mixed> $html
     */
    #[Test]
    #[DataProvider('invalidSkipRules')]
    public function it_names_the_key_of_an_invalid_skip_rule(array $html, string $key, string $problem): void
    {
        $exception = $this->configurationError(static fn(): SkipRules => HtmlRulesFactory::skipRules($html));

        self::assertSame('html-translator.html.' . $key . ': ' . $problem, $exception->getMessage());
        self::assertSame('html-translator.html.' . $key, $exception->key);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidAttributes(): iterable
    {
        yield 'type' => [['translatable_attributes' => 'title'], 'translatable_attributes', 'expected a list of strings, got "title"'];
        yield 'item type' => [['translatable_attributes' => [null]], 'translatable_attributes', 'item 0 must be a non-empty string, got null'];
        yield 'unknown key' => [['translatable_attribute' => []], 'translatable_attribute', 'unknown key; did you mean "translatable_attributes"?'];
    }

    /**
     * @param array<string, mixed> $html
     */
    #[Test]
    #[DataProvider('invalidAttributes')]
    public function it_names_the_key_of_an_invalid_attribute_list(array $html, string $key, string $problem): void
    {
        $exception = $this->configurationError(static fn(): TranslatableAttributes => HtmlRulesFactory::translatableAttributes($html));

        self::assertSame('html-translator.html.' . $key . ': ' . $problem, $exception->getMessage());
        self::assertSame('html-translator.html.' . $key, $exception->key);
    }

    /**
     * @return iterable<string, array{array<string, list<string>>, string, 'skipRules'|'translatableAttributes'}>
     */
    public static function valuesRejectedByTheCore(): iterable
    {
        yield 'element name' => [['skip_elements' => ['<div>']], 'skip_elements', 'skipRules'];
        yield 'class with a dot' => [['skip_classes' => ['.brand']], 'skip_classes', 'skipRules'];
        yield 'attribute name' => [['translatable_attributes' => ['data tooltip']], 'translatable_attributes', 'translatableAttributes'];
    }

    /**
     * @param array<string, list<string>> $html
     * @param 'skipRules'|'translatableAttributes' $method
     */
    #[Test]
    #[DataProvider('valuesRejectedByTheCore')]
    public function it_reports_core_validation_errors_under_the_key(array $html, string $key, string $method): void
    {
        try {
            match ($method) {
                'skipRules' => HtmlRulesFactory::skipRules($html),
                'translatableAttributes' => HtmlRulesFactory::translatableAttributes($html),
            };
            self::fail('Expected an InvalidConfigurationException.');
        } catch (InvalidConfigurationException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(InvalidArgumentException::class, $previous);
            self::assertSame('html-translator.html.' . $key, $exception->key);
            self::assertSame('html-translator.html.' . $key . ': ' . $previous->getMessage(), $exception->getMessage());
        }
    }
}
