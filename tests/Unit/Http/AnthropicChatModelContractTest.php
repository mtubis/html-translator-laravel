<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Http;

use HtmlTranslator\Laravel\Exceptions\NetworkException;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\Testing\ChatModelContractTestCase;
use HtmlTranslator\Provider\Llm\Testing\ChatScenario;
use HtmlTranslator\Provider\Llm\Testing\RecordedChatRequest;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The core's chat model contract for the Anthropic adapter, sent through Laravel's HTTP client: Http::fake() answers
 * like the Messages API (ADR-0004, ARCHITECTURE §7).
 */
#[CoversClass(LaravelPsr18Client::class)]
#[CoversClass(NetworkException::class)]
final class AnthropicChatModelContractTest extends ChatModelContractTestCase
{
    use FakesModelApi;

    private const string MODEL = 'contract-configured-model';

    private const int DEFAULT_MAX_OUTPUT_TOKENS = 16000;

    /**
     * Anthropic has no content filter distinct from a refusal, so FinishReason::ContentFilter cannot come from this
     * API (as in the core's own contract test).
     *
     * @return iterable<string, array{FinishReason}>
     */
    public static function finishReasons(): iterable
    {
        foreach (parent::finishReasons() as $name => $arguments) {
            if (FinishReason::ContentFilter !== $arguments[0]) {
                yield $name => $arguments;
            }
        }
    }

    protected function modelFor(ChatScenario $scenario): ChatModelInterface
    {
        $client = $this->fakeApi(
            $scenario,
            $this->message(...),
            static fn(ChatScenario $scenario): array => [
                'type' => 'error',
                'error' => array_filter(['type' => $scenario->vendorCode, 'message' => $scenario->errorMessage], static fn(?string $value): bool => null !== $value),
                'request_id' => 'req_contract',
            ],
            '{"type": "message", "content": [',
            ['type' => 'message', 'role' => 'assistant'],
        );

        return new AnthropicChatModel(
            $client,
            self::psr17(),
            self::psr17(),
            new AnthropicConfig(self::MODEL, self::API_KEY, baseUrl: 'https://api.example.test/v1', defaultMaxOutputTokens: self::DEFAULT_MAX_OUTPUT_TOKENS),
            self::retrier(),
        );
    }

    protected function recordedRequest(): RecordedChatRequest
    {
        $body = $this->lastSentBody();
        self::assertIsList($body['messages'] ?? null);

        $messages = [];
        if (isset($body['system'])) {
            self::assertIsString($body['system']);
            $messages[] = ['system', $body['system']];
        }

        foreach ($body['messages'] as $message) {
            self::assertIsArray($message);
            $messages[] = self::roleAndContent($message);
        }

        $maxTokens = $body['max_tokens'] ?? null;
        $model = $body['model'] ?? null;
        self::assertIsInt($maxTokens, 'The Messages API requires max_tokens.');
        self::assertTrue(null === $model || \is_string($model));
        $schema = $this->lastSentJson(['output_config', 'format', 'schema']);

        return new RecordedChatRequest(
            $messages,
            self::nullableFloat($body['temperature'] ?? null),
            // Only a schema enforces JSON with this API: the contract sends one, so a missing format fails it.
            null !== $schema,
            // The API requires a limit: the adapter's default stands for "the request set none".
            self::DEFAULT_MAX_OUTPUT_TOKENS === $maxTokens ? null : $maxTokens,
            $model,
            $schema,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function message(ChatScenario $scenario): array
    {
        $message = [
            'id' => 'msg_contract',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => $scenario->content]],
        ];

        $stopReason = match ($scenario->finishReason) {
            FinishReason::Stop => 'end_turn',
            FinishReason::Length => 'max_tokens',
            FinishReason::Refusal => 'refusal',
            FinishReason::Other => 'tool_use',
            FinishReason::ContentFilter, null => null,
        };
        if (null !== $stopReason) {
            $message['stop_reason'] = $stopReason;
        }

        if (null !== $scenario->model) {
            $message['model'] = $scenario->model;
        }

        if (null !== $scenario->inputTokens || null !== $scenario->outputTokens) {
            $message['usage'] = ['input_tokens' => $scenario->inputTokens, 'output_tokens' => $scenario->outputTokens];
        }

        return $message;
    }
}
