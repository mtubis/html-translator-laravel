<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Config;

use GuzzleHttp\Psr7\HttpFactory;
use HtmlTranslator\Laravel\Config\ChatModelFactory;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicEffort;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\JsonMode;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\Bridge\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

#[CoversClass(ChatModelFactory::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(ProviderFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class ChatModelFactoryTest extends TestCase
{
    use CatchesConfigurationErrors;

    private const string KEY = 'sk-test-SECRET-0123456789';

    private const string HEADER_SECRET = 'gw-SECRET-header-value';

    /**
     * Compared with the configs the core builds itself: equality covers the key and headers without reading them.
     *
     * @return iterable<string, array{string, array<string, mixed>, OpenAiCompatibleConfig}>
     */
    public static function openAiCompatibleEntries(): iterable
    {
        yield 'openai' => ['openai', ['driver' => 'openai', 'api_key' => self::KEY, 'model' => 'gpt-test'], OpenAiCompatibleConfig::openAi(self::KEY, 'gpt-test')];
        yield 'openai with json mode' => ['openai', ['driver' => 'openai', 'api_key' => self::KEY, 'model' => 'gpt-test', 'json_mode' => 'json_object'], OpenAiCompatibleConfig::openAi(self::KEY, 'gpt-test', JsonMode::JsonObject)];
        yield 'gemini' => ['gemini', ['driver' => 'gemini', 'api_key' => self::KEY, 'model' => 'gemini-test'], OpenAiCompatibleConfig::gemini(self::KEY, 'gemini-test')];
        yield 'ollama' => ['ollama', ['driver' => 'ollama', 'model' => 'llama-test', 'base_url' => ''], OpenAiCompatibleConfig::ollama('llama-test')];
        yield 'ollama with base url' => ['local', ['driver' => 'ollama', 'model' => 'llama-test', 'base_url' => 'http://ollama:11434/v1'], OpenAiCompatibleConfig::ollama('llama-test', 'http://ollama:11434/v1')];
        yield 'vllm' => ['vllm', ['driver' => 'vllm', 'model' => 'qwen-test'], OpenAiCompatibleConfig::vllm('qwen-test')];
        yield 'vllm with key and base url' => ['vllm', ['driver' => 'vllm', 'model' => 'qwen-test', 'base_url' => 'http://gpu:8000/v1', 'api_key' => self::KEY], OpenAiCompatibleConfig::vllm('qwen-test', 'http://gpu:8000/v1', self::KEY)];
        yield 'vllm with key only' => ['vllm', ['driver' => 'vllm', 'model' => 'qwen-test', 'api_key' => self::KEY], OpenAiCompatibleConfig::vllm('qwen-test', apiKey: self::KEY)];
        yield 'lm_studio' => ['lm', ['driver' => 'lm_studio', 'model' => 'phi-test'], OpenAiCompatibleConfig::lmStudio('phi-test')];
        yield 'lm_studio with base url' => ['lm', ['driver' => 'lm_studio', 'model' => 'phi-test', 'base_url' => 'http://desk:1234/v1'], OpenAiCompatibleConfig::lmStudio('phi-test', 'http://desk:1234/v1')];
        yield 'openai_compatible' => ['together', ['driver' => 'openai_compatible', 'base_url' => 'https://api.together.test/v1', 'model' => 'llama-70b'], new OpenAiCompatibleConfig('together', 'https://api.together.test/v1', 'llama-70b')];
        yield 'openai_compatible with everything' => ['together', [
            'driver' => 'openai_compatible',
            'name' => 'tg',
            'base_url' => 'https://api.together.test/v1',
            'api_key' => self::KEY,
            'model' => 'llama-70b',
            'json_mode' => 'none',
            'max_tokens_field' => 'max_completion_tokens',
            'extra_headers' => ['X-Gateway' => self::HEADER_SECRET],
        ], new OpenAiCompatibleConfig('tg', 'https://api.together.test/v1', 'llama-70b', self::KEY, JsonMode::None, 'max_completion_tokens', ['X-Gateway' => self::HEADER_SECRET])];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('openAiCompatibleEntries')]
    public function it_maps_openai_compatible_drivers(string $name, array $entry, OpenAiCompatibleConfig $expected): void
    {
        self::assertEquals($expected, self::factory()->config($name, $entry));
        self::assertInstanceOf(OpenAiCompatibleChatModel::class, self::factory()->fromArray($name, $entry));
    }

    #[Test]
    public function it_treats_an_empty_effort_from_env_as_not_set(): void
    {
        self::assertEquals(
            new AnthropicConfig('claude-test', self::KEY),
            self::factory()->config('anthropic', ['driver' => 'anthropic', 'api_key' => self::KEY, 'model' => 'claude-test', 'effort' => '']),
        );
    }

    #[Test]
    public function it_maps_the_anthropic_driver_with_core_defaults(): void
    {
        $config = self::factory()->config('anthropic', ['driver' => 'anthropic', 'api_key' => self::KEY, 'model' => 'claude-test', 'effort' => null, 'base_url' => null, 'betas' => []]);

        self::assertInstanceOf(AnthropicConfig::class, $config);
        self::assertEquals(new AnthropicConfig('claude-test', self::KEY), $config);
        self::assertInstanceOf(AnthropicChatModel::class, self::factory()->fromArray('anthropic', ['driver' => 'anthropic', 'api_key' => self::KEY, 'model' => 'claude-test']));
    }

    #[Test]
    public function it_maps_every_anthropic_option(): void
    {
        $config = self::factory()->config('claude', [
            'driver' => 'anthropic',
            'api_key' => self::KEY,
            'model' => 'claude-test',
            'effort' => 'xhigh',
            'base_url' => 'https://gateway.test/anthropic/v1',
            'refusal_fallbacks' => 'true',
            'betas' => ['beta-one'],
            'extra_headers' => ['X-Gateway' => self::HEADER_SECRET],
            'api_version' => '2027-01-01',
            'default_max_output_tokens' => '8000',
        ]);

        self::assertEquals(
            new AnthropicConfig(
                'claude-test',
                self::KEY,
                baseUrl: 'https://gateway.test/anthropic/v1',
                apiVersion: '2027-01-01',
                defaultMaxOutputTokens: 8000,
                effort: AnthropicEffort::XHigh,
                refusalFallbacks: true,
                extraHeaders: ['X-Gateway' => self::HEADER_SECRET],
                betas: ['beta-one'],
            ),
            $config,
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string, string}>
     */
    public static function missingValues(): iterable
    {
        yield 'openai key' => ['openai', ['driver' => 'openai', 'model' => 'm'], 'api_key', 'is required; the shipped configuration reads it from OPENAI_API_KEY'];
        yield 'openai model' => ['openai', ['driver' => 'openai', 'api_key' => self::KEY, 'model' => null], 'model', 'is required; the shipped configuration reads it from HTML_TRANSLATOR_OPENAI_MODEL'];
        yield 'empty openai key' => ['openai', ['driver' => 'openai', 'api_key' => '', 'model' => 'm'], 'api_key', 'is required; the shipped configuration reads it from OPENAI_API_KEY'];
        yield 'gemini key' => ['gemini', ['driver' => 'gemini', 'model' => 'm'], 'api_key', 'is required; the shipped configuration reads it from GEMINI_API_KEY'];
        yield 'gemini model' => ['gemini', ['driver' => 'gemini', 'api_key' => self::KEY], 'model', 'is required; the shipped configuration reads it from HTML_TRANSLATOR_GEMINI_MODEL'];
        yield 'anthropic key' => ['anthropic', ['driver' => 'anthropic', 'model' => 'm'], 'api_key', 'is required; the shipped configuration reads it from ANTHROPIC_API_KEY'];
        yield 'anthropic model' => ['anthropic', ['driver' => 'anthropic', 'api_key' => self::KEY, 'model' => ''], 'model', 'is required; the shipped configuration reads it from HTML_TRANSLATOR_ANTHROPIC_MODEL'];
        yield 'ollama model' => ['ollama', ['driver' => 'ollama'], 'model', 'is required; the shipped configuration reads it from HTML_TRANSLATOR_OLLAMA_MODEL'];
        yield 'vllm model' => ['vllm', ['driver' => 'vllm'], 'model', 'is required'];
        yield 'lm_studio model' => ['lm', ['driver' => 'lm_studio'], 'model', 'is required'];
        yield 'openai_compatible model' => ['together', ['driver' => 'openai_compatible', 'base_url' => 'https://x.test/v1'], 'model', 'is required'];
        yield 'openai_compatible base url' => ['together', ['driver' => 'openai_compatible', 'model' => 'm'], 'base_url', 'is required'];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('missingValues')]
    public function it_names_the_env_variable_of_a_missing_value(string $name, array $entry, string $key, string $problem): void
    {
        $exception = $this->configurationError(static fn(): mixed => self::factory()->config($name, $entry));

        self::assertSame('html-translator.providers.' . $name . '.' . $key . ': ' . $problem, $exception->getMessage());
        self::assertSame('html-translator.providers.' . $name . '.' . $key, $exception->key);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidValues(): iterable
    {
        $openAi = ['driver' => 'openai', 'api_key' => self::KEY, 'model' => 'm'];
        $anthropic = ['driver' => 'anthropic', 'api_key' => self::KEY, 'model' => 'm'];
        $compatible = ['driver' => 'openai_compatible', 'base_url' => 'https://x.test/v1', 'model' => 'm'];

        yield 'key type' => [[...$openAi, 'api_key' => 12345678], 'api_key', 'expected a string or null, got int'];
        yield 'model type' => [[...$openAi, 'model' => ['m']], 'model', 'expected a string or null, got array'];
        yield 'json mode' => [[...$openAi, 'json_mode' => 'json'], 'json_mode', 'expected one of none, json_object, json_schema, json_schema_only, got "json"'];
        yield 'effort' => [[...$anthropic, 'effort' => 'hihg'], 'effort', 'expected one of low, medium, high, xhigh, max, got "hihg"; did you mean "high"?'];
        yield 'refusal fallbacks' => [[...$anthropic, 'refusal_fallbacks' => 'yes'], 'refusal_fallbacks', 'expected a boolean, got "yes"'];
        yield 'betas' => [[...$anthropic, 'betas' => 'beta-one'], 'betas', 'expected a list of strings, got "beta-one"'];
        yield 'max output tokens' => [[...$anthropic, 'default_max_output_tokens' => 0], 'default_max_output_tokens', 'expected an integer >= 1, got 0'];
        yield 'extra header value' => [[...$anthropic, 'extra_headers' => ['X-Gateway' => 12345678]], 'extra_headers', 'the value for "X-Gateway" must be a string, got int'];
        yield 'max tokens field' => [[...$compatible, 'max_tokens_field' => 'tokens'], 'max_tokens_field', 'expected one of max_tokens, max_completion_tokens, got "tokens"'];
        yield 'name type' => [[...$compatible, 'name' => 5], 'name', 'expected a non-empty string, got 5'];
        yield 'unknown key' => [[...$openAi, 'jsonmode' => 'none'], 'jsonmode', 'unknown key; did you mean "json_mode"?'];
        yield 'key of another driver' => [[...$openAi, 'base_url' => 'https://x.test/v1'], 'base_url', 'unknown key; expected one of driver, api_key, model, json_mode'];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('invalidValues')]
    public function it_names_the_key_of_an_invalid_value(array $entry, string $key, string $problem): void
    {
        $exception = $this->configurationError(static fn(): mixed => self::factory()->config('p', $entry));

        self::assertSame('html-translator.providers.p.' . $key . ': ' . $problem, $exception->getMessage());
        self::assertStringNotContainsString(self::KEY, $exception->getMessage());
        self::assertStringNotContainsString('12345678', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function entriesRejectedByTheCore(): iterable
    {
        yield 'base url with query' => [['driver' => 'openai_compatible', 'base_url' => 'https://x.test/v1?key=' . self::KEY, 'model' => 'm', 'api_key' => self::KEY]];
        yield 'key with a line break' => [['driver' => 'anthropic', 'api_key' => self::KEY . "\n", 'model' => 'm']];
        yield 'reserved header' => [['driver' => 'anthropic', 'api_key' => self::KEY, 'model' => 'm', 'extra_headers' => ['x-api-key' => self::HEADER_SECRET]]];
        yield 'header with a line break' => [['driver' => 'openai_compatible', 'base_url' => 'https://x.test/v1', 'model' => 'm', 'extra_headers' => ['X-Gateway' => self::HEADER_SECRET . "\r\nX: 1"]]];
        yield 'invalid header name' => [['driver' => 'openai_compatible', 'base_url' => 'https://x.test/v1', 'model' => 'm', 'extra_headers' => [self::HEADER_SECRET . ' x' => 'v']]];
        yield 'invalid beta' => [['driver' => 'anthropic', 'api_key' => self::KEY, 'model' => 'm', 'betas' => ['Beta One']]];
        yield 'ollama base url' => [['driver' => 'ollama', 'model' => 'm', 'base_url' => 'localhost:11434']];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('entriesRejectedByTheCore')]
    public function it_reports_core_errors_under_the_entry_without_secrets(array $entry): void
    {
        $exception = $this->configurationError(static fn(): mixed => self::factory()->config('p', $entry));

        self::assertSame('html-translator.providers.p', $exception->key);
        self::assertStringStartsWith('html-translator.providers.p: ', $exception->getMessage());
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            self::assertStringNotContainsString(self::KEY, $current->getMessage());
            self::assertStringNotContainsString(self::HEADER_SECRET, $current->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function failingEntriesWithAKey(): iterable
    {
        yield 'package validation' => [['driver' => 'openai', 'api_key' => self::KEY, 'model' => null]];
        yield 'core validation' => [['driver' => 'anthropic', 'api_key' => self::KEY, 'model' => 'm', 'base_url' => 'ftp://x.test']];
        yield 'pseudo with a stray key' => [['driver' => 'pseudo', 'api_key' => self::KEY]];
    }

    /**
     * Debug tools and error reporters dump trace arguments; the entry and the reader hold the key.
     *
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('failingEntriesWithAKey')]
    public function it_keeps_the_key_out_of_trace_arguments(array $entry): void
    {
        $previous = (string) \ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            $exception = $this->configurationError(static fn(): mixed => new ProviderFactory(self::factory())->fromArray('p', $entry));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }

        $arguments = 0;
        foreach ($exception->getTrace() as $frame) {
            // Only the package's frames: the test's own frames hold the entry it passes in.
            $class = $frame['class'] ?? '';
            if (!str_starts_with($class, 'HtmlTranslator\\Laravel\\') || str_starts_with($class, 'HtmlTranslator\\Laravel\\Tests\\')) {
                continue;
            }

            foreach ($frame['args'] ?? [] as $argument) {
                ++$arguments;
                self::assertNotInstanceOf(ConfigReader::class, $argument, 'A reader is recorded in the trace.');
                self::assertFalse(\is_array($argument) && str_contains((string) json_encode($argument), self::KEY), 'An entry with the key is recorded in the trace.');
                self::assertFalse(\is_string($argument) && str_contains($argument, self::KEY), 'The key is recorded in the trace.');
            }
        }

        self::assertGreaterThan(0, $arguments, 'The trace recorded no arguments.');
    }

    #[Test]
    public function it_builds_a_retry_policy_from_the_http_retry_section(): void
    {
        $policy = ChatModelFactory::retryPolicy(['max_attempts' => '5', 'base_delay_ms' => 200, 'max_delay_ms' => 2000, 'retry_network_errors' => false]);

        self::assertSame(5, $policy->maxAttempts);
        self::assertSame(200, $policy->baseDelayMilliseconds);
        self::assertSame(2000, $policy->maxDelayMilliseconds);
        self::assertFalse($policy->retryNetworkErrors);
    }

    #[Test]
    public function it_builds_the_retrier_from_the_validated_section(): void
    {
        $this->expectExceptionMessage('html-translator.http.retry.max_attempts: expected an integer >= 1, got 0');

        ChatModelFactory::retrier(['max_attempts' => 0]);
    }

    #[Test]
    public function it_uses_the_core_retry_defaults(): void
    {
        self::assertEquals(new RetryPolicy(), ChatModelFactory::retryPolicy([]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRetrySections(): iterable
    {
        yield 'attempts type' => [['max_attempts' => 'three'], 'html-translator.http.retry.max_attempts: expected an integer >= 1, got "three"'];
        yield 'negative delay' => [['base_delay_ms' => -1], 'html-translator.http.retry.base_delay_ms: expected an integer >= 0, got -1'];
        yield 'network errors' => [['retry_network_errors' => 'no'], 'html-translator.http.retry.retry_network_errors: expected a boolean, got "no"'];
        yield 'unknown key' => [['attempts' => 3], 'html-translator.http.retry.attempts: unknown key; expected one of max_attempts, base_delay_ms, max_delay_ms, retry_network_errors'];
    }

    /**
     * @param array<string, mixed> $retry
     */
    #[Test]
    #[DataProvider('invalidRetrySections')]
    public function it_names_the_key_of_an_invalid_retry_option(array $retry, string $message): void
    {
        $this->expectExceptionMessage($message);

        ChatModelFactory::retryPolicy($retry);
    }

    #[Test]
    public function it_reports_core_retry_errors_under_the_section(): void
    {
        $exception = $this->configurationError(static fn(): RetryPolicy => ChatModelFactory::retryPolicy(['base_delay_ms' => 5000, 'max_delay_ms' => 1000]));

        self::assertSame('html-translator.http.retry', $exception->key);
    }

    private static function factory(): ChatModelFactory
    {
        $psr17 = new HttpFactory();

        return new ChatModelFactory(
            new class implements ClientInterface {
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException('No HTTP in this test.');
                }
            },
            $psr17,
            $psr17,
            new Retrier(),
        );
    }
}
