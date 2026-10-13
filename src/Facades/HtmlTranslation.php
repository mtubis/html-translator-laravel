<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Facades;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Laravel\HtmlTranslationManager;
use HtmlTranslator\Laravel\PendingTranslation;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;
use Illuminate\Support\Facades\Facade;

/**
 * Translate HTML with the configured providers and profiles (ARCHITECTURE §4).
 *
 * @method static HtmlTranslator translator(?string $provider = null)
 * @method static TranslatedDocument translate(string $html, Locale|string $target)
 * @method static PendingTranslation provider(string $name)
 * @method static PendingTranslation profile(TranslationProfile|string $profile)
 * @method static PendingTranslation from(Locale|string|null $source)
 * @method static PendingTranslation fallback(FallbackStrategy $fallback)
 * @method static PendingTranslation withOptions(TranslationOptions $options)
 *
 * @see HtmlTranslationManager
 */
final class HtmlTranslation extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HtmlTranslationManager::class;
    }
}
