<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel;

use Closure;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Progress;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;

/**
 * A translation being configured: every method returns a changed copy, so a pending translation can be shared and
 * reused. Unset values fall back to the configuration when translate() is called.
 */
final class PendingTranslation
{
    public private(set) ?string $providerName = null;

    public private(set) string|TranslationProfile|null $profile = null;

    public private(set) Locale|string|null $source = null;

    /** Whether from() was called, so that from(null) (detect) can be told apart from "use the configured source". */
    public private(set) bool $sourceSet = false;

    public private(set) ?FallbackStrategy $fallback = null;

    public private(set) ?int $maxCorrectiveRetries = null;

    public private(set) ?int $batchSize = null;

    public private(set) ?int $tokenBudget = null;

    /** @var (Closure(Progress): void)|null */
    public private(set) ?Closure $onProgress = null;

    /** Core options given to withOptions(), used instead of the configuration. */
    public private(set) ?TranslationOptions $coreOptions = null;

    /**
     * @internal created by the manager or the fake
     */
    public function __construct(
        private readonly TranslatesHtml $translator,
    ) {}

    public function provider(string $name): self
    {
        $pending = clone $this;
        $pending->providerName = $name;

        return $pending;
    }

    /**
     * An entry of `html-translator.profiles`, or a core profile.
     */
    public function profile(string|TranslationProfile $profile): self
    {
        $pending = clone $this;
        $pending->profile = $profile;

        return $pending;
    }

    /**
     * Null: the model detects the source language, even when `options.source` is configured.
     */
    public function from(Locale|string|null $source): self
    {
        $pending = clone $this;
        $pending->source = $source;
        $pending->sourceSet = true;

        return $pending;
    }

    public function fallback(FallbackStrategy $fallback): self
    {
        $pending = clone $this;
        $pending->fallback = $fallback;

        return $pending;
    }

    public function maxCorrectiveRetries(int $retries): self
    {
        $pending = clone $this;
        $pending->maxCorrectiveRetries = $retries;

        return $pending;
    }

    public function batchSize(int $size): self
    {
        $pending = clone $this;
        $pending->batchSize = $size;

        return $pending;
    }

    public function tokenBudget(int $tokens): self
    {
        $pending = clone $this;
        $pending->tokenBudget = $tokens;

        return $pending;
    }

    /**
     * @param Closure(Progress): void $callback
     */
    public function onProgress(Closure $callback): self
    {
        $pending = clone $this;
        $pending->onProgress = $callback;

        return $pending;
    }

    /**
     * Core options used as they are, instead of the configured options (the provider still applies). Combining them
     * with from(), profile(), fallback(), maxCorrectiveRetries(), batchSize(), tokenBudget() or onProgress() is an error:
     * set those on the TranslationOptions instead.
     */
    public function withOptions(TranslationOptions $options): self
    {
        $pending = clone $this;
        $pending->coreOptions = $options;

        return $pending;
    }

    /**
     * withOptions() replaces every override, so combining them would silently drop one side; the manager and the fake
     * both refuse it.
     *
     * @internal called by the manager and the fake
     *
     * @throws LogicException
     */
    public function assertNoOverridesBesideCoreOptions(): void
    {
        if (!$this->coreOptions instanceof TranslationOptions) {
            return;
        }

        if ($this->sourceSet || null !== $this->profile || $this->fallback instanceof FallbackStrategy || null !== $this->maxCorrectiveRetries
            || null !== $this->batchSize || null !== $this->tokenBudget || $this->onProgress instanceof Closure) {
            throw new LogicException('withOptions() replaces the configured options and every override; set from(), profile(), fallback(), maxCorrectiveRetries(), batchSize(), tokenBudget() and onProgress() on the TranslationOptions instead.');
        }
    }

    public function translate(string $html, Locale|string $target): TranslatedDocument
    {
        return $this->translator->translatePending($this, $html, $target);
    }
}
