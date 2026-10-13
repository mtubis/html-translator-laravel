<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Laravel\Config\ChatModelFactory;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\HtmlTranslatorServiceProvider;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use HtmlTranslator\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Every LLM driver end to end: config entry → ProviderFactory → LaravelPsr18Client → core HtmlTranslator, with
 * Http::fake() answering in the vendor's format.
 */
#[CoversClass(ProviderFactory::class)]
#[CoversClass(ChatModelFactory::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(LaravelPsr18Client::class)]
#[UsesClass(HtmlTranslatorServiceProvider::class)]
final class ProviderDriversTest extends TestCase
{
    private const string KEY = 'sk-test-SECRET-0123456789';

    /**
     * Entry, URL, header names that must be sent (only names: values are secrets), model in the body, a body field the
     * API requires (null: none beyond the model; OpenAI-compatible adapters send an output limit only when one is
     * requested, which the unit tests cover), vendor format.
     *
     * @return iterable<string, array{array<string, mixed>, string, list<string>, string, ?string, 'openai'|'anthropic'}>
     */
    public static function drivers(): iterable
    {
        yield 'openai' => [['driver' => 'openai', 'api_key' => self::KEY, 'model' => 'gpt-test'], 'https://api.openai.com/v1/chat/completions', ['Authorization'], 'gpt-test', null, 'openai'];
        yield 'gemini' => [['driver' => 'gemini', 'api_key' => self::KEY, 'model' => 'gemini-test'], 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', ['Authorization'], 'gemini-test', null, 'openai'];
        yield 'ollama' => [['driver' => 'ollama', 'model' => 'llama-test'], 'http://localhost:11434/v1/chat/completions', [], 'llama-test', null, 'openai'];
        yield 'vllm' => [['driver' => 'vllm', 'model' => 'qwen-test', 'api_key' => self::KEY], 'http://localhost:8000/v1/chat/completions', ['Authorization'], 'qwen-test', null, 'openai'];
        yield 'lm_studio' => [['driver' => 'lm_studio', 'model' => 'phi-test'], 'http://localhost:1234/v1/chat/completions', [], 'phi-test', null, 'openai'];
        yield 'openai_compatible' => [
            ['driver' => 'openai_compatible', 'base_url' => 'https://api.together.test/v1', 'api_key' => self::KEY, 'model' => 'llama-70b', 'max_tokens_field' => 'max_completion_tokens', 'extra_headers' => ['X-Gateway' => 'gw-SECRET-token']],
            'https://api.together.test/v1/chat/completions',
            ['Authorization', 'X-Gateway'],
            'llama-70b',
            null,
            'openai',
        ];
        yield 'anthropic' => [
            ['driver' => 'anthropic', 'api_key' => self::KEY, 'model' => 'claude-test', 'extra_headers' => ['X-Gateway' => 'gw-SECRET-token']],
            'https://api.anthropic.com/v1/messages',
            ['x-api-key', 'X-Gateway'],
            'claude-test',
            'max_tokens',
            'anthropic',
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string> $headers
     * @param 'openai'|'anthropic' $format
     */
    #[Test]
    #[DataProvider('drivers')]
    public function it_translates_through_each_llm_driver(array $entry, string $url, array $headers, string $model, ?string $requiredField, string $format): void
    {
        Http::preventStrayRequests();
        Http::fake([$url => static fn(Request $request): PromiseInterface => Factory::response(self::vendorAnswer($format, self::translatedUnits($request)))]);

        $document = new HtmlTranslator($this->providers()->fromArray('test', $entry))->translate('<p>Hello</p>', Locale::fromString('de'));

        self::assertStringContainsString('<p>Hallo</p>', $document->html());
        self::assertTrue($document->isComplete());
        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request) use ($url, $headers, $model, $requiredField): bool {
            foreach ($headers as $header) {
                if (!$request->hasHeader($header)) {
                    return false;
                }
            }

            $body = $request->data();

            return $url === $request->url()
                && 'POST' === $request->method()
                && ([] !== $headers || (!$request->hasHeader('Authorization') && !$request->hasHeader('x-api-key')))
                && $model === ($body['model'] ?? null)
                && (null === $requiredField || \array_key_exists($requiredField, $body));
        });
    }

    #[Test]
    public function it_translates_with_the_pseudo_driver_without_http(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $document = new HtmlTranslator($this->providers()->fromArray('pseudo', ['driver' => 'pseudo']))->translate('<p>Hello</p>', Locale::fromString('de'));

        self::assertStringNotContainsString('<p>Hello</p>', $document->html());
        Http::assertNothingSent();
    }

    private function providers(): ProviderFactory
    {
        $psr17 = new HttpFactory();

        return new ProviderFactory(new ChatModelFactory(
            new LaravelPsr18Client($this->app?->make(Factory::class) ?? self::fail('No application.'), 30, 5),
            $psr17,
            $psr17,
            ChatModelFactory::retrier([]),
        ));
    }

    /**
     * The model's answer for every unit id in the request: {"units": [{"id": …, "text": "Hallo"}]}.
     */
    private static function translatedUnits(Request $request): string
    {
        $content = '';
        $body = $request->data();
        foreach (\is_array($body['messages'] ?? null) ? $body['messages'] : [] as $message) {
            $content .= \is_array($message) && \is_string($message['content'] ?? null) ? $message['content'] : '';
        }

        preg_match_all('/"id":\s*"([^"]+)"/', $content, $matches);
        self::assertNotEmpty($matches[1], 'The request contains no units.');

        return json_encode(['units' => array_map(static fn(string $id): array => ['id' => $id, 'text' => 'Hallo'], $matches[1])], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param 'openai'|'anthropic' $format
     *
     * @return array<string, mixed>
     */
    private static function vendorAnswer(string $format, string $answer): array
    {
        return 'anthropic' === $format
            ? ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => $answer]], 'stop_reason' => 'end_turn', 'model' => 'claude-test']
            : ['object' => 'chat.completion', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $answer], 'finish_reason' => 'stop']], 'model' => 'test'];
    }
}
