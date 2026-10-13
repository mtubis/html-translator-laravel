<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Http;

use HtmlTranslator\Laravel\Exceptions\NetworkException;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\JsonMode;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\Testing\ChatModelContractTestCase;
use HtmlTranslator\Provider\Llm\Testing\ChatScenario;
use HtmlTranslator\Provider\Llm\Testing\RecordedChatRequest;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The core's chat model contract for the OpenAI-compatible adapter, sent through Laravel's HTTP client: Http::fake()
 * answers like the Chat Completions API (ADR-0004, ARCHITECTURE §7).
 */
#[CoversClass(LaravelPsr18Client::class)]
#[CoversClass(NetworkException::class)]
final class OpenAiCompatibleChatModelContractTest extends ChatModelContractTestCase
{
    use FakesModelApi;

    private const string MODEL = 'contract-configured-model';

    protected function modelFor(ChatScenario $scenario): ChatModelInterface
    {
        $client = $this->fakeApi(
            $scenario,
            $this->completion(...),
            static fn(ChatScenario $scenario): array => ['error' => ['message' => $scenario->errorMessage, 'type' => null, 'param' => null, 'code' => $scenario->vendorCode]],
            '{"choices": [{"message": ',
            ['object' => 'chat.completion', 'choices' => []],
        );

        return new OpenAiCompatibleChatModel(
            $client,
            self::psr17(),
            self::psr17(),
            new OpenAiCompatibleConfig('contract', 'https://api.example.test/v1', self::MODEL, self::API_KEY, JsonMode::JsonSchema),
            self::retrier(),
        );
    }

    protected function recordedRequest(): RecordedChatRequest
    {
        $body = $this->lastSentBody();
        self::assertIsList($body['messages'] ?? null);

        $messages = [];
        foreach ($body['messages'] as $message) {
            self::assertIsArray($message);
            $messages[] = self::roleAndContent($message);
        }

        $format = $body['response_format'] ?? null;
        self::assertTrue(null === $format || \is_array($format));
        $maxTokens = $body['max_tokens'] ?? null;
        $model = $body['model'] ?? null;
        self::assertTrue(null === $maxTokens || \is_int($maxTokens));
        self::assertTrue(null === $model || \is_string($model));

        return new RecordedChatRequest(
            $messages,
            self::nullableFloat($body['temperature'] ?? null),
            \in_array($format['type'] ?? null, ['json_object', 'json_schema'], true),
            $maxTokens,
            $model,
            $this->lastSentJson(['response_format', 'json_schema', 'schema']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function completion(ChatScenario $scenario): array
    {
        $message = FinishReason::Refusal === $scenario->finishReason
            ? ['role' => 'assistant', 'content' => null, 'refusal' => $scenario->content]
            : ['role' => 'assistant', 'content' => $scenario->content];
        $choice = ['index' => 0, 'message' => $message];

        $finishReason = match ($scenario->finishReason) {
            FinishReason::Stop, FinishReason::Refusal => 'stop',
            FinishReason::Length => 'length',
            FinishReason::ContentFilter => 'content_filter',
            FinishReason::Other => 'tool_calls',
            null => null,
        };
        if (null !== $finishReason) {
            $choice['finish_reason'] = $finishReason;
        }

        $completion = ['object' => 'chat.completion', 'choices' => [$choice]];
        if (null !== $scenario->model) {
            $completion['model'] = $scenario->model;
        }

        if (null !== $scenario->inputTokens || null !== $scenario->outputTokens) {
            $completion['usage'] = ['prompt_tokens' => $scenario->inputTokens, 'completion_tokens' => $scenario->outputTokens];
        }

        return $completion;
    }
}
