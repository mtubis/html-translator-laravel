<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Testing;

use Closure;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Laravel\Config\OptionsFactory;
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;
use HtmlTranslator\Laravel\PendingTranslation;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;
use HtmlTranslator\TranslationStats;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Stands in for the manager behind HtmlTranslation::fake() (ADR-0006): no network, no configuration. Translations are
 * pseudo-localized by the core (or answered from canned responses) and recorded for the assertions.
 *
 * translator() returns a pseudo-localizing core translator; translations made directly on it are not recorded.
 */
final class HtmlTranslationFake implements TranslatesHtml
{
    /** @var list<RecordedTranslation> */
    private array $recorded = [];

    private ?Throwable $failure = null;

    private ?HtmlTranslator $translator = null;

    /** @var array<string, Closure(string): string> */
    private readonly array $responses;

    /**
     * @param array<string, Closure(string $html): string> $responses canned HTML per target locale ("de", "pt-BR"),
     *                                                                "*" for any other target
     */
    public function __construct(array $responses = [])
    {
        $normalized = [];
        foreach ($responses as $locale => $response) {
            $locale = (string) $locale;
            if (!$response instanceof Closure) {
                throw new InvalidArgumentException(\sprintf('HtmlTranslation::fake(): the response for "%s" must be a Closure(string $html): string, got %s.', $locale, get_debug_type($response)));
            }

            try {
                $normalized['*' === $locale ? '*' : Locale::fromString($locale)->toString()] = $response;
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('HtmlTranslation::fake(): ' . $exception->getMessage(), 0, $exception);
            }
        }

        $this->responses = $normalized;
    }

    /**
     * One pseudo-localizing core translator for every provider name.
     */
    public function translator(?string $provider = null): HtmlTranslator
    {
        return $this->translator ??= new HtmlTranslator(new PseudoLocalizationProvider());
    }

    public function translate(string $html, Locale|string $target): TranslatedDocument
    {
        return $this->translatePending(new PendingTranslation($this), $html, $target);
    }

    public function provider(string $name): PendingTranslation
    {
        return new PendingTranslation($this)->provider($name);
    }

    public function profile(string|TranslationProfile $profile): PendingTranslation
    {
        return new PendingTranslation($this)->profile($profile);
    }

    public function from(Locale|string|null $source): PendingTranslation
    {
        return new PendingTranslation($this)->from($source);
    }

    public function fallback(FallbackStrategy $fallback): PendingTranslation
    {
        return new PendingTranslation($this)->fallback($fallback);
    }

    public function withOptions(TranslationOptions $options): PendingTranslation
    {
        return new PendingTranslation($this)->withOptions($options);
    }

    /**
     * @internal called by PendingTranslation::translate()
     */
    public function translatePending(PendingTranslation $pending, string $html, Locale|string $target): TranslatedDocument
    {
        $pending->assertNoOverridesBesideCoreOptions();
        $targetLocale = $target instanceof Locale ? $target : Locale::fromString($target);
        $source = $pending->coreOptions->source ?? (\is_string($pending->source) ? Locale::fromString($pending->source) : $pending->source);
        $profile = $pending->coreOptions->profile ?? $pending->profile;

        $result = null;
        try {
            if ($this->failure instanceof Throwable) {
                throw $this->failure;
            }

            return $result = $this->answer($pending, $html, $targetLocale, $source);
        } finally {
            $this->recorded[] = new RecordedTranslation(
                $html,
                $targetLocale->toString(),
                $source?->toString(),
                $pending->providerName,
                $profile instanceof TranslationProfile ? $profile->fingerprint() : $profile,
                $result,
            );
        }
    }

    /**
     * Makes every following translation throw $exception, e.g. a core ProviderException to test error handling. The
     * translations are still recorded.
     */
    public function failWith(Throwable $exception): self
    {
        $this->failure = $exception;

        return $this;
    }

    /**
     * @param (Closure(RecordedTranslation): bool)|null $filter
     *
     * @return list<RecordedTranslation>
     */
    public function recorded(?Closure $filter = null): array
    {
        return $filter instanceof Closure ? array_values(array_filter($this->recorded, $filter)) : $this->recorded;
    }

    /**
     * @param (Closure(RecordedTranslation): bool)|null $callback
     */
    public function assertTranslated(?Closure $callback = null): void
    {
        Assert::assertTrue(
            [] !== $this->recorded($callback),
            $callback instanceof Closure
                ? \sprintf('No recorded translation matches the callback (%s).', $this->summary())
                : 'Expected HTML to be translated; nothing was translated.',
        );
    }

    public function assertTranslatedTo(string $locale): void
    {
        $locale = Locale::fromString($locale)->toString();

        Assert::assertTrue(
            [] !== $this->recorded(static fn(RecordedTranslation $translation): bool => $translation->target === $locale),
            \sprintf('Expected a translation to %s; %s.', $locale, $this->summary()),
        );
    }

    /**
     * @param (Closure(RecordedTranslation): bool)|null $callback null: nothing may be translated at all
     */
    public function assertNotTranslated(?Closure $callback = null): void
    {
        $matching = $this->recorded($callback);
        Assert::assertTrue(
            [] === $matching,
            $callback instanceof Closure
                ? \sprintf('Unexpected translation matching the callback: %d of %s.', \count($matching), $this->summary())
                : \sprintf('Expected nothing to be translated; %s.', $this->summary()),
        );
    }

    public function assertTranslatedTimes(int $times): void
    {
        Assert::assertTrue(
            $times === \count($this->recorded),
            \sprintf('Expected %d %s; got %d.', $times, 1 === $times ? 'translation' : 'translations', \count($this->recorded)),
        );
    }

    public function assertNothingTranslated(): void
    {
        Assert::assertTrue([] === $this->recorded, \sprintf('Expected nothing to be translated; %s.', $this->summary()));
    }

    private function answer(PendingTranslation $pending, string $html, Locale $target, ?Locale $source): TranslatedDocument
    {
        $response = $this->responses[$target->toString()] ?? $this->responses['*'] ?? null;
        if ($response instanceof Closure) {
            return new TranslatedDocument($response($html), true, [], new TranslationStats(0, 0, 0, 0.0));
        }

        // The core defaults plus the overrides; named profiles need configuration, so they are only recorded.
        $options = $pending->coreOptions ?? OptionsFactory::fromArray(
            [],
            profile: $pending->profile instanceof TranslationProfile ? $pending->profile : null,
            source: $source,
            fallback: $pending->fallback,
            maxCorrectiveRetries: $pending->maxCorrectiveRetries,
            batchSize: $pending->batchSize,
            tokenBudget: $pending->tokenBudget,
            onProgress: $pending->onProgress,
        );

        return $this->translator()->translate($html, $target, $options);
    }

    private function summary(): string
    {
        $count = \count($this->recorded);

        return 0 === $count
            ? 'nothing was translated'
            : \sprintf('%d %s recorded, to %s', $count, 1 === $count ? 'translation' : 'translations', implode(', ', $this->targets()));
    }

    /**
     * @return list<string>
     */
    private function targets(): array
    {
        return array_map(static fn(RecordedTranslation $translation): string => $translation->target, $this->recorded);
    }
}
