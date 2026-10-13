<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Http;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\Testing\ChatScenario;
use HtmlTranslator\Provider\Llm\Testing\ChatScenarioKind;
use HtmlTranslator\Provider\Llm\Testing\FakeSleeper;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Psr\Http\Message\RequestInterface;

/**
 * Runs a core chat model contract on LaravelPsr18Client: Http::fake() plays the vendor API for each ChatScenario, and
 * the request the adapter sent is read back from Laravel's recorded requests.
 */
trait FakesModelApi
{
    private ?Factory $http = null;

    private string $ignoreArgs = '';

    /**
     * The core's contract enables zend.exception_ignore_args=0 in setUp() and never restores it; left on, later tests
     * record whole object graphs (the application) in every exception trace.
     */
    protected function setUp(): void
    {
        $this->ignoreArgs = (string) ini_get('zend.exception_ignore_args');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        parent::tearDown();
    }

    /**
     * @param Closure(ChatScenario): array<string, mixed> $successBody
     * @param Closure(ChatScenario): array<string, mixed> $errorBody
     * @param array<string, mixed> $unexpectedShape
     */
    private function fakeApi(ChatScenario $scenario, Closure $successBody, Closure $errorBody, string $malformedBody, array $unexpectedShape): LaravelPsr18Client
    {
        $this->http = new Factory();
        $this->http->fake(static fn(): PromiseInterface|Closure => match ($scenario->kind) {
            ChatScenarioKind::Success => Factory::response($successBody($scenario)),
            ChatScenarioKind::HttpError => $scenario->jsonErrorBody
                ? Factory::response($errorBody($scenario), $scenario->statusCode ?? 500)
                : Factory::response('<html><body><h1>502 Bad Gateway</h1>' . $scenario->errorMessage . '</body></html>', $scenario->statusCode ?? 502, ['Content-Type' => 'text/html']),
            ChatScenarioKind::MalformedBody => Factory::response($malformedBody, 200, ['Content-Type' => 'application/json']),
            ChatScenarioKind::UnexpectedShape => Factory::response($unexpectedShape),
            ChatScenarioKind::TransportError, ChatScenarioKind::NetworkError => Factory::failedConnection($scenario->errorMessage),
        });

        return new LaravelPsr18Client($this->http, 180, 10);
    }

    private static function psr17(): HttpFactory
    {
        return new HttpFactory();
    }

    private static function retrier(): Retrier
    {
        return new Retrier(sleeper: new FakeSleeper());
    }

    /**
     * @return array<mixed>
     */
    private function lastSentBody(): array
    {
        $body = json_decode((string) $this->lastSentRequest()->getBody(), true, 64, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * The JSON schema exactly as sent: decoding to arrays would turn an empty {} into [].
     *
     * @param list<string> $path
     */
    private function lastSentJson(array $path): ?string
    {
        $value = json_decode((string) $this->lastSentRequest()->getBody(), false, 64, \JSON_THROW_ON_ERROR);
        foreach ($path as $key) {
            $value = \is_object($value) ? get_object_vars($value)[$key] ?? null : null;
        }

        return null === $value ? null : json_encode($value, \JSON_THROW_ON_ERROR);
    }

    private function lastSentRequest(): RequestInterface
    {
        $pairs = $this->http?->recorded() ?? [];
        $last = $pairs[\count($pairs) - 1][0] ?? self::fail('No request was sent.');
        self::assertInstanceOf(Request::class, $last);

        return $last->toPsrRequest();
    }

    /**
     * @param array<mixed> $message
     *
     * @return array{string, string}
     */
    private static function roleAndContent(array $message): array
    {
        self::assertIsString($message['role'] ?? null);
        self::assertIsString($message['content'] ?? null);

        return [$message['role'], $message['content']];
    }

    private static function nullableFloat(mixed $value): ?float
    {
        self::assertTrue(null === $value || \is_float($value) || \is_int($value));

        return null === $value ? null : (float) $value;
    }
}
