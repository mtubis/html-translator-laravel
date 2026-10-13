<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Config;

use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\TranslationProviderInterface;
use SensitiveParameter;

/**
 * Builds a core translation provider from an entry of `html-translator.providers`: the pseudo-localization provider,
 * or an LLM provider around the chat model of the entry's driver.
 *
 * @internal built by the service provider; applications use the facade or the manager
 */
final readonly class ProviderFactory
{
    /** Every driver the configuration may name; "laravel_ai" is reserved for version 0.3. */
    public const array DRIVERS = [...ChatModelFactory::DRIVERS, 'pseudo', 'laravel_ai'];

    private const string PREFIX = 'html-translator.providers.';

    public function __construct(
        private ChatModelFactory $chatModels,
    ) {}

    /**
     * @param array<mixed> $entry
     */
    public function fromArray(string $name, #[SensitiveParameter] array $entry): TranslationProviderInterface
    {
        $reader = new ConfigReader($entry, self::PREFIX . $name);

        return match ($reader->oneOf('driver', self::DRIVERS)) {
            'pseudo' => $this->pseudo($reader),
            'laravel_ai' => throw InvalidConfigurationException::forKey($reader->key('driver'), 'the "laravel_ai" driver is planned for version 0.3 and not available yet'),
            default => new LlmTranslationProvider($this->chatModels->fromArray($name, $entry)),
        };
    }

    private function pseudo(#[SensitiveParameter] ConfigReader $reader): PseudoLocalizationProvider
    {
        $reader->assertKnownKeys(['driver']);

        return new PseudoLocalizationProvider();
    }
}
