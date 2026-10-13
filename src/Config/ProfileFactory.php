<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Config;

use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\TermPolicy;
use HtmlTranslator\Profile\TranslationProfile;

/**
 * Builds a core TranslationProfile from an entry of `html-translator.profiles`.
 *
 * The preset is the starting point; every other key overrides it only when set, so `null` keeps the preset's value.
 *
 * @internal built by the service provider and manager; applications use the facade or the manager
 */
final class ProfileFactory
{
    private const string PREFIX = 'html-translator.profiles.';

    private const array PRESETS = ['default', 'technical', 'marketing', 'legal'];

    private const array KEYS = ['preset', 'domain', 'formality', 'term_policy', 'glossary', 'do_not_translate', 'instructions'];

    /**
     * @param array<mixed> $entry
     */
    public static function fromArray(string $name, array $entry): TranslationProfile
    {
        $reader = new ConfigReader($entry, self::PREFIX . $name);
        $reader->assertKnownKeys(self::KEYS);

        $profile = match ($reader->oneOf('preset', self::PRESETS, 'default')) {
            'technical' => TranslationProfile::technical(),
            'marketing' => TranslationProfile::marketing(),
            'legal' => TranslationProfile::legal(),
            default => TranslationProfile::default(),
        };

        $domain = $reader->nullableString('domain');
        if (null !== $domain) {
            $profile = $reader->guard('domain', static fn(): TranslationProfile => $profile->withDomain($domain));
        }

        if ($reader->has('formality')) {
            $profile = $profile->withFormality($reader->enum('formality', Formality::class));
        }

        if ($reader->has('term_policy')) {
            $profile = $profile->withTermPolicy($reader->enum('term_policy', TermPolicy::class));
        }

        $glossary = $reader->stringMap('glossary');
        if ([] !== $glossary) {
            $profile = $reader->guard('glossary', static fn(): TranslationProfile => $profile->withGlossary(Glossary::fromArray($glossary)));
        }

        $doNotTranslate = $reader->stringList('do_not_translate');
        if ([] !== $doNotTranslate) {
            $profile = $reader->guard('do_not_translate', static fn(): TranslationProfile => $profile->withDoNotTranslate(...$doNotTranslate));
        }

        $instructions = $reader->nullableString('instructions');
        if (null !== $instructions) {
            return $reader->guard('instructions', static fn(): TranslationProfile => $profile->withInstructions($instructions));
        }

        return $profile;
    }
}
