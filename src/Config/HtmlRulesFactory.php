<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Config;

use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;

/**
 * Builds the core SkipRules and TranslatableAttributes from `html-translator.html`. The configured lists are added to
 * the core defaults, never replace them.
 *
 * @internal built by the service provider and manager; applications use the facade or the manager
 */
final class HtmlRulesFactory
{
    private const string PREFIX = 'html-translator.html';

    private const array KEYS = ['skip_elements', 'skip_classes', 'translatable_attributes'];

    /**
     * @param array<mixed> $html the `html` section
     */
    public static function skipRules(array $html): SkipRules
    {
        $reader = self::reader($html);
        $rules = SkipRules::defaults();

        foreach ($reader->stringList('skip_elements') as $element) {
            $rules = $reader->guard('skip_elements', static fn(): SkipRules => $rules->withElement($element));
        }

        foreach ($reader->stringList('skip_classes') as $class) {
            $rules = $reader->guard('skip_classes', static fn(): SkipRules => $rules->withClass($class));
        }

        return $rules;
    }

    /**
     * @param array<mixed> $html the `html` section
     */
    public static function translatableAttributes(array $html): TranslatableAttributes
    {
        $reader = self::reader($html);
        $attributes = TranslatableAttributes::defaults();

        foreach ($reader->stringList('translatable_attributes') as $attribute) {
            $attributes = $reader->guard('translatable_attributes', static fn(): TranslatableAttributes => $attributes->withAttribute($attribute));
        }

        return $attributes;
    }

    /**
     * @param array<mixed> $html
     */
    private static function reader(array $html): ConfigReader
    {
        $reader = new ConfigReader($html, self::PREFIX);
        $reader->assertKnownKeys(self::KEYS);

        return $reader;
    }
}
