<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Config;

use Closure;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Progress;
use HtmlTranslator\TranslationOptions;

/**
 * Builds core TranslationOptions from `html-translator.options` and per-call overrides.
 *
 * Missing configuration keys fall back to the core defaults. Overrides come from code (PendingTranslation, Artisan
 * options), not from configuration, so invalid overrides fail with the core's own exception.
 *
 * @internal built by the service provider and manager; applications use the facade or the manager
 */
final class OptionsFactory
{
    private const string PREFIX = 'html-translator.options';

    private const array KEYS = ['source', 'fallback', 'max_corrective_retries', 'batch_size', 'token_budget'];

    /** FallbackStrategy is a pure enum: the configuration names are mapped explicitly. */
    private const array FALLBACKS = [
        'keep_original' => FallbackStrategy::KeepOriginal,
        'text_nodes_only' => FallbackStrategy::TextNodesOnly,
        'throw' => FallbackStrategy::Throw,
    ];

    /**
     * @param array<mixed> $options the `options` section
     * @param (Closure(Progress): void)|null $onProgress
     */
    public static function fromArray(
        array $options,
        ?TranslationProfile $profile = null,
        Locale|string|null $source = null,
        ?FallbackStrategy $fallback = null,
        ?int $maxCorrectiveRetries = null,
        ?int $batchSize = null,
        ?int $tokenBudget = null,
        ?Closure $onProgress = null,
    ): TranslationOptions {
        // The configuration is validated even where an override replaces it, so an error does not depend on the call.
        $reader = new ConfigReader($options, self::PREFIX);
        $reader->assertKnownKeys(self::KEYS);
        $defaults = new TranslationOptions();
        $configuredSource = self::configuredSource($reader);
        $configuredFallback = self::configuredFallback($reader) ?? $defaults->fallback;
        $configuredRetries = $reader->nonNegativeInt('max_corrective_retries', $defaults->maxCorrectiveRetries);
        $configuredBatchSize = $reader->positiveInt('batch_size', $defaults->batchSize);
        $configuredTokenBudget = $reader->positiveInt('token_budget', $defaults->tokenBudget);

        return new TranslationOptions(
            source: \is_string($source) ? Locale::fromString($source) : ($source ?? $configuredSource),
            fallback: $fallback ?? $configuredFallback,
            maxCorrectiveRetries: $maxCorrectiveRetries ?? $configuredRetries,
            batchSize: $batchSize ?? $configuredBatchSize,
            onProgress: $onProgress,
            profile: $profile,
            tokenBudget: $tokenBudget ?? $configuredTokenBudget,
        );
    }

    private static function configuredSource(ConfigReader $reader): ?Locale
    {
        $source = $reader->nullableString('source');

        return null === $source ? null : $reader->guard('source', static fn(): Locale => Locale::fromString($source));
    }

    private static function configuredFallback(ConfigReader $reader): ?FallbackStrategy
    {
        if (!$reader->has('fallback')) {
            return null;
        }

        $name = $reader->oneOf('fallback', array_keys(self::FALLBACKS));

        return self::FALLBACKS[$name] ?? throw new LogicException(\sprintf('No fallback strategy for "%s".', $name));
    }
}
