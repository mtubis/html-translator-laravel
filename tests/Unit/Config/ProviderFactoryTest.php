<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Config;

use GuzzleHttp\Psr7\HttpFactory;
use HtmlTranslator\Laravel\Config\ChatModelFactory;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\TranslationProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

#[CoversClass(ProviderFactory::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class ProviderFactoryTest extends TestCase
{
    use CatchesConfigurationErrors;

    #[Test]
    public function it_builds_the_pseudo_provider(): void
    {
        self::assertEquals(new PseudoLocalizationProvider(), self::factory()->fromArray('pseudo', ['driver' => 'pseudo']));
    }

    #[Test]
    public function it_wraps_a_chat_model_for_llm_drivers(): void
    {
        $provider = self::factory()->fromArray('openai', ['driver' => 'openai', 'api_key' => 'sk-test', 'model' => 'gpt-test']);

        self::assertInstanceOf(LlmTranslationProvider::class, $provider);
        self::assertStringContainsString('openai:gpt-test', $provider->cacheId());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'missing driver' => [[], 'driver', 'is required'];
        yield 'unknown driver' => [
            ['driver' => 'openia'],
            'driver',
            'expected one of openai, gemini, ollama, vllm, lm_studio, openai_compatible, anthropic, pseudo, laravel_ai, got "openia"; did you mean "openai"?',
        ];
        yield 'driver type' => [['driver' => 1], 'driver', 'expected one of openai, gemini, ollama, vllm, lm_studio, openai_compatible, anthropic, pseudo, laravel_ai, got 1'];
        yield 'planned driver' => [['driver' => 'laravel_ai'], 'driver', 'the "laravel_ai" driver is planned for version 0.3 and not available yet'];
        yield 'pseudo option' => [['driver' => 'pseudo', 'model' => 'x'], 'model', 'unknown key; expected one of driver'];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('invalidEntries')]
    public function it_names_the_key_of_an_invalid_entry(array $entry, string $key, string $problem): void
    {
        $exception = $this->configurationError(static fn(): TranslationProviderInterface => self::factory()->fromArray('mine', $entry));

        self::assertSame('html-translator.providers.mine.' . $key . ': ' . $problem, $exception->getMessage());
        self::assertSame('html-translator.providers.mine.' . $key, $exception->key);
    }

    private static function factory(): ProviderFactory
    {
        $psr17 = new HttpFactory();

        return new ProviderFactory(new ChatModelFactory(
            new class implements ClientInterface {
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException('No HTTP in this test.');
                }
            },
            $psr17,
            $psr17,
            new Retrier(),
        ));
    }
}
