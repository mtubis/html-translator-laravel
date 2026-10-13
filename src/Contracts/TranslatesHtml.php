<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Contracts;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Laravel\PendingTranslation;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;

/**
 * What the HtmlTranslation facade offers: implemented by the manager and by the fake (ADR-0006).
 */
interface TranslatesHtml
{
    /**
     * The core translator of a provider entry (null: the default provider), resolved once per entry.
     */
    public function translator(?string $provider = null): HtmlTranslator;

    public function translate(string $html, Locale|string $target): TranslatedDocument;

    public function provider(string $name): PendingTranslation;

    /**
     * An entry of `html-translator.profiles`, or a core profile.
     */
    public function profile(string|TranslationProfile $profile): PendingTranslation;

    /**
     * Null: the model detects the source language, even when `options.source` is configured.
     */
    public function from(Locale|string|null $source): PendingTranslation;

    public function fallback(FallbackStrategy $fallback): PendingTranslation;

    /**
     * Core options used as they are, instead of the configured options and any overrides.
     */
    public function withOptions(TranslationOptions $options): PendingTranslation;

    /**
     * @internal called by PendingTranslation::translate()
     */
    public function translatePending(PendingTranslation $pending, string $html, Locale|string $target): TranslatedDocument;
}
