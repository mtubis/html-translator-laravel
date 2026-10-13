<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel;

use Closure;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\HtmlRulesFactory;
use HtmlTranslator\Laravel\Config\OptionsFactory;
use HtmlTranslator\Laravel\Config\ProfileFactory;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * The root of the HtmlTranslation facade (ADR-0006): resolves one core HtmlTranslator per provider entry and starts
 * pending translations. Configuration is validated when a provider or profile is first used (ADR-0007).
 *
 * The configuration is read when the manager is resolved: after changing it at runtime, call
 * app()->forgetInstance(HtmlTranslationManager::class).
 */
final class HtmlTranslationManager implements TranslatesHtml
{
    private const string PREFIX = 'html-translator';

    /** @var array<string, HtmlTranslator> */
    private array $translators = [];

    /** The configuration holds API keys: kept out of dumps and serialization, as the core keeps its secrets. */
    private readonly SensitiveParameterValue $config;

    /**
     * @param array<mixed> $config the whole `html-translator` configuration; holds API keys
     */
    public function __construct(
        #[SensitiveParameter]
        array $config,
        private readonly ProviderFactory $providers,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->config = new SensitiveParameterValue($config);
    }

    public function translator(?string $provider = null): HtmlTranslator
    {
        $name = $provider ?? $this->reader()->string('default');

        return $this->translators[$name] ??= $this->build($name);
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
        $options = $this->options($pending);

        return $this->translator($pending->providerName)->translate(
            $html,
            $target instanceof Locale ? $target : Locale::fromString($target),
            $options,
        );
    }

    /**
     * The core options of a pending translation: the configured options with its overrides.
     *
     * @internal public for the tests and the fake
     */
    public function options(PendingTranslation $pending): TranslationOptions
    {
        if ($pending->coreOptions instanceof TranslationOptions) {
            if ($pending->sourceSet || null !== $pending->profile || $pending->fallback instanceof FallbackStrategy || null !== $pending->maxCorrectiveRetries
                || null !== $pending->batchSize || null !== $pending->tokenBudget || $pending->onProgress instanceof Closure) {
                throw new LogicException('withOptions() replaces the configured options and every override; set from(), profile(), fallback(), maxCorrectiveRetries(), batchSize(), tokenBudget() and onProgress() on the TranslationOptions instead.');
            }

            return $pending->coreOptions;
        }

        $options = $this->section('options');
        if ($pending->sourceSet && null === $pending->source) {
            $options['source'] = null;
        }

        return OptionsFactory::fromArray(
            $options,
            profile: $pending->profile instanceof TranslationProfile ? $pending->profile : $this->namedProfile($pending->profile),
            source: $pending->source,
            fallback: $pending->fallback,
            maxCorrectiveRetries: $pending->maxCorrectiveRetries,
            batchSize: $pending->batchSize,
            tokenBudget: $pending->tokenBudget,
            onProgress: $pending->onProgress,
        );
    }

    /**
     * What dd(), dump(), var_dump() and print_r() show: names, never the configuration's values.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $providers = $this->config()['providers'] ?? [];

        return [
            'default' => $this->config()['default'] ?? null,
            'providers' => \is_array($providers) ? array_keys($providers) : [],
            'translators' => array_keys($this->translators),
            'cache' => get_debug_type($this->cache),
            'logger' => get_debug_type($this->logger),
        ];
    }

    private function build(string $name): HtmlTranslator
    {
        $html = $this->section('html');

        return new HtmlTranslator(
            $this->providers->fromArray($name, $this->entry('providers', $name)),
            $this->cache,
            $this->logger,
            skipRules: HtmlRulesFactory::skipRules($html),
            translatableAttributes: HtmlRulesFactory::translatableAttributes($html),
        );
    }

    private function namedProfile(?string $name): TranslationProfile
    {
        $name ??= $this->reader()->string('default_profile', 'default');

        return ProfileFactory::fromArray($name, $this->entry('profiles', $name));
    }

    /**
     * @return array<mixed>
     */
    private function entry(string $section, string $name): array
    {
        $entries = $this->section($section);
        if (!\array_key_exists($name, $entries)) {
            throw InvalidConfigurationException::forKey(
                self::PREFIX . '.' . $section . '.' . $name,
                'is not defined; defined: ' . implode(', ', array_keys($entries)),
            );
        }

        $entry = $entries[$name];
        if (!\is_array($entry)) {
            throw InvalidConfigurationException::forKey(self::PREFIX . '.' . $section . '.' . $name, 'expected an array, got ' . get_debug_type($entry));
        }

        return $entry;
    }

    /**
     * @return array<mixed>
     */
    private function section(string $section): array
    {
        $value = $this->config()[$section] ?? [];
        if (!\is_array($value)) {
            throw InvalidConfigurationException::forKey(self::PREFIX . '.' . $section, 'expected an array, got ' . get_debug_type($value));
        }

        return $value;
    }

    private function reader(): ConfigReader
    {
        return new ConfigReader($this->config(), self::PREFIX);
    }

    /**
     * @return array<mixed>
     */
    private function config(): array
    {
        $config = $this->config->getValue();

        return \is_array($config) ? $config : [];
    }
}
