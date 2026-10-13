<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Config;

use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicEffort;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\JsonMode;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\Bridge\RetryPolicy;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * Builds a core chat model from an LLM entry of `html-translator.providers` (core docs/providers.md).
 *
 * Entries hold API keys: they are #[SensitiveParameter] wherever they are passed, so exception traces never record
 * them.
 *
 * @internal built by the service provider; applications use the facade or the manager
 */
final readonly class ChatModelFactory
{
    public const array DRIVERS = ['openai', 'gemini', 'ollama', 'vllm', 'lm_studio', 'openai_compatible', 'anthropic'];

    private const string PREFIX = 'html-translator.providers.';

    private const string RETRY_PREFIX = 'html-translator.http.retry';

    /** Keys besides "driver", per driver. */
    private const array KEYS = [
        'openai' => ['api_key', 'model', 'json_mode'],
        'gemini' => ['api_key', 'model'],
        'ollama' => ['model', 'base_url'],
        'vllm' => ['model', 'base_url', 'api_key'],
        'lm_studio' => ['model', 'base_url'],
        'openai_compatible' => ['name', 'base_url', 'api_key', 'model', 'json_mode', 'max_tokens_field', 'extra_headers'],
        'anthropic' => ['api_key', 'model', 'effort', 'base_url', 'refusal_fallbacks', 'betas', 'extra_headers', 'api_version', 'default_max_output_tokens'],
    ];

    /** The env variables the shipped config reads required values from, named in "is required" messages. */
    private const array ENV = [
        'openai' => ['api_key' => 'OPENAI_API_KEY', 'model' => 'HTML_TRANSLATOR_OPENAI_MODEL'],
        'gemini' => ['api_key' => 'GEMINI_API_KEY', 'model' => 'HTML_TRANSLATOR_GEMINI_MODEL'],
        'anthropic' => ['api_key' => 'ANTHROPIC_API_KEY', 'model' => 'HTML_TRANSLATOR_ANTHROPIC_MODEL'],
        'ollama' => ['model' => 'HTML_TRANSLATOR_OLLAMA_MODEL'],
    ];

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private Retrier $retrier,
    ) {}

    /**
     * @param array<mixed> $entry
     */
    public function fromArray(string $name, #[SensitiveParameter] array $entry): ChatModelInterface
    {
        $config = $this->config($name, $entry);

        return $config instanceof AnthropicConfig
            ? new AnthropicChatModel($this->client, $this->requestFactory, $this->streamFactory, $config, $this->retrier)
            : new OpenAiCompatibleChatModel($this->client, $this->requestFactory, $this->streamFactory, $config, $this->retrier);
    }

    /**
     * The core configuration an entry maps to; fromArray() wraps it in its chat model.
     *
     * @param array<mixed> $entry
     */
    public function config(string $name, #[SensitiveParameter] array $entry): OpenAiCompatibleConfig|AnthropicConfig
    {
        $reader = new ConfigReader($entry, self::PREFIX . $name);
        $driver = $reader->oneOf('driver', self::DRIVERS);
        $reader->assertKnownKeys(['driver', ...self::KEYS[$driver] ?? []]);

        return match ($driver) {
            'openai' => $this->openAi($reader),
            'gemini' => $this->gemini($reader),
            'ollama' => $this->ollama($reader),
            'vllm' => $this->vllm($reader),
            'lm_studio' => $this->lmStudio($reader),
            'anthropic' => $this->anthropic($reader),
            'openai_compatible' => $this->openAiCompatible($reader, $name),
            default => throw new LogicException(\sprintf('No mapping for the driver "%s".', $driver)),
        };
    }

    /**
     * @param array<mixed> $retry the `http.retry` section
     */
    public static function retrier(array $retry): Retrier
    {
        return new Retrier(self::retryPolicy($retry));
    }

    /**
     * @param array<mixed> $retry the `http.retry` section
     */
    public static function retryPolicy(array $retry): RetryPolicy
    {
        $reader = new ConfigReader($retry, self::RETRY_PREFIX);
        $reader->assertKnownKeys(['max_attempts', 'base_delay_ms', 'max_delay_ms', 'retry_network_errors']);
        $defaults = new RetryPolicy();
        $maxAttempts = $reader->positiveInt('max_attempts', $defaults->maxAttempts);
        $baseDelay = $reader->nonNegativeInt('base_delay_ms', $defaults->baseDelayMilliseconds);
        $maxDelay = $reader->nonNegativeInt('max_delay_ms', $defaults->maxDelayMilliseconds);
        $retryNetworkErrors = $reader->bool('retry_network_errors', $defaults->retryNetworkErrors);

        return $reader->guardEntry(static fn(): RetryPolicy => new RetryPolicy(
            maxAttempts: $maxAttempts,
            baseDelayMilliseconds: $baseDelay,
            maxDelayMilliseconds: $maxDelay,
            retryNetworkErrors: $retryNetworkErrors,
        ));
    }

    private function openAi(#[SensitiveParameter] ConfigReader $reader): OpenAiCompatibleConfig
    {
        $apiKey = $this->required($reader, 'openai', 'api_key');
        $model = $this->required($reader, 'openai', 'model');
        $jsonMode = $reader->enum('json_mode', JsonMode::class, JsonMode::JsonSchema);

        return $reader->guardEntry(static fn(): OpenAiCompatibleConfig => OpenAiCompatibleConfig::openAi($apiKey, $model, $jsonMode));
    }

    private function gemini(#[SensitiveParameter] ConfigReader $reader): OpenAiCompatibleConfig
    {
        $apiKey = $this->required($reader, 'gemini', 'api_key');
        $model = $this->required($reader, 'gemini', 'model');

        return $reader->guardEntry(static fn(): OpenAiCompatibleConfig => OpenAiCompatibleConfig::gemini($apiKey, $model));
    }

    private function ollama(#[SensitiveParameter] ConfigReader $reader): OpenAiCompatibleConfig
    {
        $model = $this->required($reader, 'ollama', 'model');
        $baseUrl = $reader->nullableString('base_url');

        return $reader->guardEntry(static fn(): OpenAiCompatibleConfig => null === $baseUrl
            ? OpenAiCompatibleConfig::ollama($model)
            : OpenAiCompatibleConfig::ollama($model, $baseUrl));
    }

    private function vllm(#[SensitiveParameter] ConfigReader $reader): OpenAiCompatibleConfig
    {
        $model = $this->required($reader, 'vllm', 'model');
        $baseUrl = $reader->nullableString('base_url');
        $apiKey = $reader->secret('api_key');

        return $reader->guardEntry(static fn(): OpenAiCompatibleConfig => null === $baseUrl
            ? OpenAiCompatibleConfig::vllm($model, apiKey: $apiKey)
            : OpenAiCompatibleConfig::vllm($model, $baseUrl, $apiKey));
    }

    private function lmStudio(#[SensitiveParameter] ConfigReader $reader): OpenAiCompatibleConfig
    {
        $model = $this->required($reader, 'lm_studio', 'model');
        $baseUrl = $reader->nullableString('base_url');

        return $reader->guardEntry(static fn(): OpenAiCompatibleConfig => null === $baseUrl
            ? OpenAiCompatibleConfig::lmStudio($model)
            : OpenAiCompatibleConfig::lmStudio($model, $baseUrl));
    }

    private function openAiCompatible(#[SensitiveParameter] ConfigReader $reader, string $name): OpenAiCompatibleConfig
    {
        $configName = $reader->string('name', $name);
        $baseUrl = $this->required($reader, 'openai_compatible', 'base_url');
        $model = $this->required($reader, 'openai_compatible', 'model');
        $apiKey = $reader->secret('api_key');
        $jsonMode = $reader->enum('json_mode', JsonMode::class, JsonMode::JsonObject);
        $maxTokensField = $reader->oneOf('max_tokens_field', [OpenAiCompatibleConfig::MAX_TOKENS, OpenAiCompatibleConfig::MAX_COMPLETION_TOKENS], OpenAiCompatibleConfig::MAX_TOKENS);
        $extraHeaders = $reader->secretMap('extra_headers');

        return $reader->guardEntry(static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig($configName, $baseUrl, $model, $apiKey, $jsonMode, $maxTokensField, $extraHeaders));
    }

    private function anthropic(#[SensitiveParameter] ConfigReader $reader): AnthropicConfig
    {
        $apiKey = $this->required($reader, 'anthropic', 'api_key');
        $model = $this->required($reader, 'anthropic', 'model');
        $baseUrl = $reader->nullableString('base_url') ?? AnthropicConfig::DEFAULT_BASE_URL;
        $apiVersion = $reader->nullableString('api_version') ?? AnthropicConfig::DEFAULT_API_VERSION;
        $maxOutputTokens = $reader->positiveInt('default_max_output_tokens', AnthropicConfig::DEFAULT_MAX_OUTPUT_TOKENS);
        // The shipped config reads it from env: an empty HTML_TRANSLATOR_ANTHROPIC_EFFORT= means "not set".
        $effort = null === $reader->nullableString('effort') ? null : $reader->enum('effort', AnthropicEffort::class);
        $refusalFallbacks = $reader->bool('refusal_fallbacks', false);
        $extraHeaders = $reader->secretMap('extra_headers');
        $betas = $reader->stringList('betas');

        return $reader->guardEntry(static fn(): AnthropicConfig => new AnthropicConfig(
            $model,
            $apiKey,
            baseUrl: $baseUrl,
            apiVersion: $apiVersion,
            defaultMaxOutputTokens: $maxOutputTokens,
            effort: $effort,
            refusalFallbacks: $refusalFallbacks,
            extraHeaders: $extraHeaders,
            betas: $betas,
        ));
    }

    /**
     * A required string; "api_key" is read as a secret. The message names the env variable the shipped config reads.
     */
    private function required(#[SensitiveParameter] ConfigReader $reader, string $driver, string $key): string
    {
        $value = 'api_key' === $key ? $reader->secret($key) : $reader->nullableString($key);
        if (null !== $value) {
            return $value;
        }

        $env = self::ENV[$driver][$key] ?? null;

        throw InvalidConfigurationException::forKey(
            $reader->key($key),
            null === $env ? 'is required' : 'is required; the shipped configuration reads it from ' . $env,
        );
    }
}
