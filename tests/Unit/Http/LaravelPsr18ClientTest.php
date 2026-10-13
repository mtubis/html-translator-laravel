<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Http;

use Closure;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\HttpFactory;
use HtmlTranslator\Laravel\Exceptions\NetworkException;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\Testing\FakeSleeper;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use SensitiveParameterValue;
use Throwable;

#[CoversClass(LaravelPsr18Client::class)]
#[CoversClass(NetworkException::class)]
final class LaravelPsr18ClientTest extends TestCase
{
    private const string API_KEY = 'sk-test-SECRET-0123456789';

    private const string URL = 'https://api.example.test/v1/chat/completions';

    private Factory $http;

    private HttpFactory $psr17;

    private ?string $ignoreArgs = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new Factory();
        $this->psr17 = new HttpFactory();
    }

    #[Test]
    public function it_sends_the_request_unchanged(): void
    {
        $this->http->fake(['*' => Factory::response(['ok' => true])]);
        $body = '{"model":"m","messages":[{"role":"user","content":"Hello"}],"temperature":0.0}';

        $this->client()->sendRequest(
            $this->psr17->createRequest('POST', self::URL . '?api-version=2026-01-01')
                ->withHeader('Authorization', 'Bearer ' . self::API_KEY)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('X-Custom', ['a', 'b'])
                ->withBody($this->psr17->createStream($body)),
        );

        $this->http->assertSentCount(1);
        $this->http->assertSent(static function (Request $request) use ($body): bool {
            $psr = $request->toPsrRequest();

            return 'POST' === $psr->getMethod()
                && self::URL . '?api-version=2026-01-01' === (string) $psr->getUri()
                && ['Bearer ' . self::API_KEY] === $psr->getHeader('Authorization')
                && ['application/json; charset=utf-8'] === $psr->getHeader('Content-Type')
                && ['a', 'b'] === $psr->getHeader('X-Custom')
                && $body === (string) $psr->getBody();
        });
    }

    #[Test]
    public function it_adds_no_content_type_or_body_to_a_request_without_them(): void
    {
        $this->http->fake(['*' => Factory::response('pong')]);

        $this->client()->sendRequest($this->psr17->createRequest('GET', 'https://api.example.test/v1/models'));

        $this->http->assertSent(static fn(Request $request): bool => 'GET' === $request->method()
            && !$request->hasHeader('Content-Type')
            && '' === $request->body());
    }

    #[Test]
    public function it_passes_timeouts_and_disables_redirects(): void
    {
        $options = [];
        $this->http->fake(static function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Factory::response('ok');
        });

        new LaravelPsr18Client($this->http, 120, 7)->sendRequest($this->psr17->createRequest('GET', self::URL));

        self::assertSame(120, $options['timeout'] ?? null);
        self::assertSame(7, $options['connect_timeout'] ?? null);
        self::assertFalse($options['allow_redirects'] ?? null);
        self::assertFalse($options['http_errors'] ?? null);
    }

    #[Test]
    public function it_returns_error_statuses_as_responses(): void
    {
        $this->http->fake(['*' => $this->http->sequence()
            ->push('{"error":"nope"}', 404, ['Content-Type' => 'application/json'])
            ->push('boom', 500)
            ->push('', 302, ['Location' => 'https://elsewhere.example.test/'])]);

        $notFound = $this->client()->sendRequest($this->psr17->createRequest('GET', self::URL));
        $serverError = $this->client()->sendRequest($this->psr17->createRequest('GET', self::URL));
        $redirect = $this->client()->sendRequest($this->psr17->createRequest('GET', self::URL));

        self::assertSame(404, $notFound->getStatusCode());
        self::assertSame('{"error":"nope"}', (string) $notFound->getBody());
        self::assertSame(['application/json'], $notFound->getHeader('Content-Type'));
        self::assertSame(500, $serverError->getStatusCode());
        self::assertSame('boom', (string) $serverError->getBody());
        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame(['https://elsewhere.example.test/'], $redirect->getHeader('Location'));
        $this->http->assertSentCount(3);
    }

    #[Test]
    public function it_lets_the_core_retrier_retry_rate_limits(): void
    {
        $this->http->fake(['*' => $this->http->sequence()
            ->push(['error' => ['message' => 'slow down', 'code' => 'rate_limit_exceeded']], 429)
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Cześć'], 'finish_reason' => 'stop']], 'model' => 'gpt-test'])]);
        $model = new OpenAiCompatibleChatModel(
            $this->client(),
            $this->psr17,
            $this->psr17,
            new OpenAiCompatibleConfig('openai', 'https://api.example.test/v1', 'gpt-test', self::API_KEY),
            new Retrier(sleeper: new FakeSleeper()),
        );

        $response = $model->complete(new ChatRequest([new ChatMessage(ChatRole::User, 'Hello')]));

        self::assertSame('Cześć', $response->content);
        $this->http->assertSentCount(2);
    }

    #[Test]
    public function it_lets_the_core_retrier_retry_an_overloaded_anthropic_api(): void
    {
        $this->http->fake(['*' => $this->http->sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)
            ->push(['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Cześć']], 'stop_reason' => 'end_turn', 'model' => 'claude-test'])]);
        $model = new AnthropicChatModel(
            $this->client(),
            $this->psr17,
            $this->psr17,
            new AnthropicConfig('claude-test', self::API_KEY),
            new Retrier(sleeper: new FakeSleeper()),
        );

        $response = $model->complete(new ChatRequest([new ChatMessage(ChatRole::User, 'Hello')]));

        self::assertSame('Cześć', $response->content);
        $this->http->assertSentCount(2);
    }

    #[Test]
    public function it_turns_a_connection_failure_into_a_redacted_network_exception(): void
    {
        // Traces with arguments, so a request or key passed as an argument would show up.
        $this->ignoreArgs = (string) \ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $request = $this->psr17->createRequest('POST', self::URL . '?key=' . self::API_KEY)
            ->withHeader('Authorization', 'Bearer ' . self::API_KEY)
            ->withHeader('X-Gateway-Token', 'gw-SECRET-token');
        $this->http->fake(['*' => Factory::failedConnection(
            'cURL error 28: Operation timed out after 30001 milliseconds for ' . self::URL . '?key=' . self::API_KEY
            . ' (Authorization: Bearer ' . self::API_KEY . ', X-Gateway-Token: gw-SECRET-token)',
        )]);

        $exception = $this->networkException(fn(): mixed => $this->client()->sendRequest($request));

        self::assertSame($request, $exception->getRequest());
        self::assertNull($exception->getPrevious());
        self::assertSame(
            'cURL error 28: Operation timed out after 30001 milliseconds for ' . self::URL
            . ' (Authorization: [redacted], X-Gateway-Token: [redacted])',
            $exception->getMessage(),
        );
        $this->assertNoSecretIn($exception);
    }

    #[Test]
    public function it_redacts_query_values_that_appear_without_the_full_uri(): void
    {
        $request = $this->psr17->createRequest('GET', 'https://user:pass-SECRET-1@api.example.test/v1/models?key=' . self::API_KEY . '&page=2');
        $this->http->fake(['*' => Factory::failedConnection('Could not resolve host; key=' . self::API_KEY . ' user pass-SECRET-1')]);

        $exception = $this->networkException(fn(): mixed => $this->client()->sendRequest($request));

        self::assertSame('Could not resolve host; key=[redacted] user [redacted]', $exception->getMessage());
    }

    #[Test]
    public function it_redacts_header_values_from_the_minimum_length_and_longest_first(): void
    {
        $request = $this->psr17->createRequest('GET', 'https://user:pa:ss-SECRET@api.example.test/v1/models')
            ->withHeader('X-Short', '1234567')
            ->withHeader('X-Exact', '12345678')
            ->withHeader('X-Prefix', 'token-AB')
            ->withHeader('X-Longer', "token-AB-CD\tsecond-word");
        $this->http->fake(['*' => Factory::failedConnection('1234567 12345678 token-AB-CD second-word pa:ss-SECRET')]);

        $exception = $this->networkException(fn(): mixed => $this->client()->sendRequest($request));

        self::assertSame('1234567 [redacted] [redacted] [redacted]', $exception->getMessage());
    }

    #[Test]
    public function it_turns_a_guzzle_error_without_request_into_a_network_exception(): void
    {
        $request = $this->psr17->createRequest('GET', self::URL)->withHeader('Authorization', 'Bearer ' . self::API_KEY);
        $this->http->fake(static fn(): PromiseInterface => Create::rejectionFor(
            new class ('Transfer failed for Bearer ' . self::API_KEY) extends RuntimeException implements GuzzleException {},
        ));

        $exception = $this->networkException(fn(): mixed => $this->client()->sendRequest($request));

        self::assertSame('Transfer failed for [redacted]', $exception->getMessage());
        self::assertSame($request, $exception->getRequest());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function it_turns_a_transfer_that_failed_after_the_headers_into_a_network_exception(): void
    {
        $request = $this->psr17->createRequest('GET', self::URL)->withHeader('Authorization', 'Bearer ' . self::API_KEY);
        $this->http->fake(static fn(): never => throw new RequestException(new Response(Factory::psr7Response('partial', 500))));

        $exception = $this->networkException(fn(): mixed => $this->client()->sendRequest($request));

        self::assertStringContainsString('500', $exception->getMessage());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function it_returns_error_statuses_even_when_the_application_enables_http_errors(): void
    {
        $this->http->globalOptions(['http_errors' => true]);
        $this->http->fake(['*' => Factory::response(['error' => 'rate'], 429, ['Retry-After' => '3'])]);

        $response = $this->client()->sendRequest($this->psr17->createRequest('GET', self::URL));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(['3'], $response->getHeader('Retry-After'));
    }

    #[Test]
    public function it_redacts_encoded_query_values_from_uris_quoted_with_masked_user_info(): void
    {
        $key = 'sk/abc+def==XYZ123';
        $request = $this->psr17->createRequest('GET', 'https://user:pass@api.example.test/v1?key=' . rawurlencode($key));
        $encoded = $request->getUri()->getQuery();
        $this->http->fake(['*' => Factory::failedConnection(
            'cURL error 7: Failed to connect for https://***@api.example.test/v1?' . $encoded . ' (' . $key . ')',
        )]);

        $exception = $this->networkException(fn(): mixed => $this->client()->sendRequest($request));

        self::assertSame('cURL error 7: Failed to connect for https://***@api.example.test/v1 ([redacted])', $exception->getMessage());
    }

    #[Test]
    public function it_removes_the_user_info_of_the_quoted_uri(): void
    {
        $request = $this->psr17->createRequest('GET', 'https://gateway:pw@api.example.test/v1#part');
        $this->http->fake(['*' => Factory::failedConnection('Failed for https://gateway:pw@api.example.test/v1#part')]);

        $exception = $this->networkException(fn(): mixed => $this->client()->sendRequest($request));

        self::assertSame('Failed for https://api.example.test/v1', $exception->getMessage());
    }

    /**
     * The request goes out as the core built it (ADR-0004): what an application adds to every Laravel request is
     * dropped. This pins the behaviour, so a change in Laravel's middleware order is noticed.
     */
    #[Test]
    public function it_sends_the_core_request_instead_of_what_global_middleware_and_options_add(): void
    {
        $this->http->globalRequestMiddleware(static fn(RequestInterface $request): RequestInterface => $request->withHeader('X-Trace', 'abc'));
        $this->http->globalOptions(['headers' => ['X-Global' => 'yes']]);
        $this->http->fake(['*' => Factory::response('ok')]);

        $this->client()->sendRequest($this->psr17->createRequest('GET', self::URL)->withHeader('X-Core', '1'));

        $this->http->assertSent(static fn(Request $request): bool => $request->hasHeader('X-Core')
            && !$request->hasHeader('X-Trace')
            && !$request->hasHeader('X-Global'));
    }

    /**
     * Laravel's events and therefore Telescope see the request with its credentials, as ADR-0004 intends.
     */
    #[Test]
    public function it_announces_the_core_request_in_laravel_events(): void
    {
        $events = new Dispatcher();
        $sending = [];
        $failed = [];
        $events->listen(RequestSending::class, static function (RequestSending $event) use (&$sending): void {
            $sending[] = $event->request->toPsrRequest();
        });
        $events->listen(ConnectionFailed::class, static function (ConnectionFailed $event) use (&$failed): void {
            $failed[] = $event->request->toPsrRequest();
        });
        $this->http = new Factory($events);
        $this->http->fake(['*' => Factory::failedConnection('Timed out')]);
        $request = $this->psr17->createRequest('POST', self::URL)->withHeader('x-api-key', self::API_KEY)->withBody($this->psr17->createStream('{}'));

        $this->networkException(fn(): mixed => $this->client()->sendRequest($request));

        self::assertCount(1, $sending);
        self::assertCount(1, $failed);
        foreach ([...$sending, ...$failed] as $announced) {
            self::assertSame('POST', $announced->getMethod());
            self::assertSame(self::URL, (string) $announced->getUri());
            self::assertSame([self::API_KEY], $announced->getHeader('x-api-key'));
            self::assertSame('{}', (string) $announced->getBody());
        }
    }

    #[Test]
    public function it_keeps_the_request_out_of_dumps(): void
    {
        // Without trace arguments: this test is about the request property, and recorded arguments would hold the
        // whole test case and application, which print_r() would dump for minutes.
        $this->ignoreArgs = (string) \ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '1');
        $request = $this->psr17->createRequest('GET', self::URL)->withHeader('Authorization', 'Bearer ' . self::API_KEY);
        $exception = new NetworkException('Timed out', $request);

        self::assertSame($request, $exception->getRequest());
        self::assertStringNotContainsString(self::API_KEY, print_r($exception, true));
        self::assertStringNotContainsString(self::API_KEY, var_export($exception, true));
    }

    protected function tearDown(): void
    {
        if (null !== $this->ignoreArgs) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }

        parent::tearDown();
    }

    private function client(): LaravelPsr18Client
    {
        return new LaravelPsr18Client($this->http, 180, 10);
    }

    /**
     * @param Closure(): mixed $call
     */
    private function networkException(Closure $call): NetworkException
    {
        try {
            $call();
        } catch (NetworkException $exception) {
            return $exception;
        }

        self::fail('Expected a NetworkException.');
    }

    /**
     * Debug tools and error reporters dump messages, chains and trace arguments.
     */
    private function assertNoSecretIn(Throwable $exception): void
    {
        self::assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        self::assertStringNotContainsString('gw-SECRET-token', $exception->getMessage());
        self::assertStringNotContainsString(self::API_KEY, (string) $exception);

        $arguments = 0;
        foreach ($exception->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                ++$arguments;
                self::assertFalse($argument instanceof RequestInterface || $argument instanceof Throwable, 'A trace argument holds the request.');
                if ($argument instanceof SensitiveParameterValue) {
                    continue;
                }

                self::assertFalse(\is_string($argument) && str_contains($argument, self::API_KEY), 'A trace argument contains the key.');
            }
        }

        self::assertGreaterThan(0, $arguments, 'The trace recorded no arguments; zend.exception_ignore_args must be 0.');
    }
}
