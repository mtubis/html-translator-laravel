<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Facades;

use Closure;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;
use HtmlTranslator\Laravel\HtmlTranslationManager;
use HtmlTranslator\Laravel\PendingTranslation;
use HtmlTranslator\Laravel\Testing\HtmlTranslationFake;
use HtmlTranslator\Laravel\Testing\RecordedTranslation;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;
use Illuminate\Support\Facades\Facade;
use Throwable;

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
 * @method static HtmlTranslationFake failWith(Throwable $exception)
 * @method static list<RecordedTranslation> recorded(Closure|null $filter = null)
 * @method static void assertTranslated(Closure|null $callback = null)
 * @method static void assertTranslatedTo(string $locale)
 * @method static void assertNotTranslated(Closure|null $callback = null)
 * @method static void assertTranslatedTimes(int $times)
 * @method static void assertNothingTranslated()
 *
 * @see HtmlTranslationManager
 * @see HtmlTranslationFake
 */
final class HtmlTranslation extends Facade
{
    /**
     * Replaces the manager with a fake that pseudo-localizes (or answers from $responses) and records translations,
     * for the assertions of HtmlTranslationFake.
     *
     * @param array<string, Closure(string $html): string> $responses canned HTML per target locale, "*" for any other
     */
    public static function fake(array $responses = []): HtmlTranslationFake
    {
        $fake = new HtmlTranslationFake($responses);
        self::swap($fake);

        return $fake;
    }

    /**
     * The contract, not the manager: fake() rebinds it, while code type-hinting the manager keeps the real one.
     */
    protected static function getFacadeAccessor(): string
    {
        return TranslatesHtml::class;
    }
}
