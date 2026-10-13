<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Laravel\Config\ChatModelFactory;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\HtmlRulesFactory;
use HtmlTranslator\Laravel\Config\OptionsFactory;
use HtmlTranslator\Laravel\Config\ProfileFactory;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Laravel\HtmlTranslationManager;
use HtmlTranslator\Laravel\HtmlTranslatorServiceProvider;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use HtmlTranslator\Laravel\PendingTranslation;
use HtmlTranslator\Laravel\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Cache\Adapter\Psr16Adapter;

#[CoversClass(HtmlTranslatorServiceProvider::class)]
#[UsesClass(HtmlTranslationManager::class)]
#[UsesClass(PendingTranslation::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(ProviderFactory::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(ProfileFactory::class)]
#[UsesClass(OptionsFactory::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
#[UsesClass(LaravelPsr18Client::class)]
final class ServiceProviderWiringTest extends TestCase
{
    private const string HTML = '<p>Hello</p><p>Second paragraph</p>';

    #[Test]
    public function it_serves_a_second_translation_of_the_same_html_from_the_cache(): void
    {
        $manager = $this->manager();

        $first = $manager->translate(self::HTML, 'de');
        $second = $manager->translate(self::HTML, 'de');

        self::assertSame(0, $first->stats()->cacheHits);
        self::assertSame($second->stats()->units, $second->stats()->cacheHits);
        self::assertSame($first->html(), $second->html());
        self::assertInstanceOf(Psr16Adapter::class, $this->app()->make(HtmlTranslatorServiceProvider::CACHE));
    }

    #[Test]
    public function it_caches_in_the_named_store(): void
    {
        config()->set('html-translator.cache.store', 'array');
        config()->set('html-translator.cache.ttl', 60);

        $manager = $this->manager();
        $manager->translate(self::HTML, 'de');

        self::assertGreaterThan(0, $manager->translate(self::HTML, 'de')->stats()->cacheHits);
    }

    #[Test]
    public function it_does_not_cache_when_disabled(): void
    {
        config()->set('html-translator.cache.enabled', false);
        $manager = $this->manager();

        $manager->translate(self::HTML, 'de');

        self::assertSame(0, $manager->translate(self::HTML, 'de')->stats()->cacheHits);
        self::assertNull($this->app()->make(HtmlTranslatorServiceProvider::CACHE));
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'cache ttl' => ['cache.ttl', 0, 'html-translator.cache.ttl: expected an integer >= 1, got 0'];
        yield 'cache store' => ['cache.store', 'nope', 'html-translator.cache.store: '];
        yield 'cache prefix' => ['cache.prefix', 'with:colon', 'html-translator.cache.prefix: '];
        yield 'cache key' => ['cache.time', 5, 'html-translator.cache.time: unknown key'];
        yield 'unknown log channel' => ['log_channel', 'translatons', 'html-translator.log_channel: the channel "translatons" is not defined in config/logging.php; defined: '];
        yield 'log channel' => ['log_channel', 5, 'html-translator.log_channel: expected a channel name, null (the default channel) or false (no logging), got int'];
        yield 'http timeout' => ['http.timeout', 'slow', 'html-translator.http.timeout: expected an integer >= 1, got "slow"'];
        yield 'http section' => ['http', 'fast', 'html-translator.http: expected an array, got string'];
        yield 'http retry' => ['http.retry', 3, 'html-translator.http.retry: expected an array, got int'];
        yield 'http key' => ['http.timeouts', 5, 'html-translator.http.timeouts: unknown key; did you mean "timeout"?'];
    }

    #[Test]
    #[DataProvider('invalidSettings')]
    public function it_names_the_key_of_an_invalid_setting_when_the_manager_is_resolved(string $key, mixed $value, string $message): void
    {
        config()->set('html-translator.' . $key, $value);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->manager();
    }

    #[Test]
    public function it_logs_core_issues_to_the_configured_channel(): void
    {
        config()->set('logging.channels.translations', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        config()->set('html-translator.log_channel', 'translations');
        config()->set('html-translator.providers.broken', ['driver' => 'pseudo', 'corrupt_every' => 1, 'corrupt_retries' => true]);

        $document = $this->manager()->provider('broken')->translate(self::HTML, 'de');

        self::assertFalse($document->isComplete());
        $channel = $this->app()->make(LogManager::class)->channel('translations');
        self::assertInstanceOf(IlluminateLogger::class, $channel);
        $monolog = $channel->getLogger();
        self::assertInstanceOf(Logger::class, $monolog);
        $handler = $monolog->getHandlers()[0] ?? null;
        self::assertInstanceOf(TestHandler::class, $handler);
        self::assertTrue($handler->hasWarningRecords());
    }

    #[Test]
    public function it_logs_to_the_default_channel_or_not_at_all(): void
    {
        self::assertInstanceOf(LoggerInterface::class, $this->app()->make(HtmlTranslatorServiceProvider::LOGGER));

        config()->set('html-translator.log_channel', false);
        $this->app()->forgetInstance(HtmlTranslatorServiceProvider::LOGGER);

        self::assertNull($this->app()->make(HtmlTranslatorServiceProvider::LOGGER));
    }

    #[Test]
    public function it_uses_an_http_client_bound_by_the_application(): void
    {
        Http::preventStrayRequests();
        config()->set('html-translator.default', 'openai');
        config()->set('html-translator.providers.openai', ['driver' => 'openai', 'api_key' => 'sk-test', 'model' => 'gpt-test']);
        $this->app()->instance(HtmlTranslatorServiceProvider::HTTP_CLIENT, new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('The application client was called for ' . $request->getUri());
            }
        });

        $this->expectExceptionMessage('The application client was called for https://api.openai.com/v1/chat/completions');

        $this->manager()->translate(self::HTML, 'de');
    }

    #[Test]
    public function it_binds_the_manager_under_the_contract(): void
    {
        self::assertSame($this->manager(), $this->app()->make(TranslatesHtml::class));
    }

    #[Test]
    public function it_caches_when_the_enabled_key_is_missing(): void
    {
        config()->set('html-translator.cache', ['store' => null]);

        self::assertInstanceOf(Psr16Adapter::class, $this->app()->make(HtmlTranslatorServiceProvider::CACHE));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, int}>
     */
    public static function timeouts(): iterable
    {
        yield 'defaults' => [['retry' => []], 180, 10];
        yield 'configured' => [['timeout' => '45', 'connect_timeout' => 3, 'retry' => []], 45, 3];
    }

    /**
     * @param array<string, mixed> $http
     */
    #[Test]
    #[DataProvider('timeouts')]
    public function it_passes_the_configured_timeouts_to_the_http_client(array $http, int $timeout, int $connectTimeout): void
    {
        config()->set('html-translator.http', $http);
        config()->set('html-translator.default', 'local');
        config()->set('html-translator.providers.local', ['driver' => 'ollama', 'model' => 'llama-test']);
        $options = [];
        Http::fake(static function (Request $request, array $sent) use (&$options): PromiseInterface {
            $options = $sent;

            return Factory::response('', 500);
        });

        try {
            $this->manager()->translator()->translate('<p>Hello</p>', Locale::fromString('de'));
        } catch (ProviderException) {
            // Only the request options matter here.
        }

        self::assertSame($timeout, $options['timeout'] ?? null);
        self::assertSame($connectTimeout, $options['connect_timeout'] ?? null);
    }

    #[Test]
    public function it_checks_the_http_section_also_with_an_application_client(): void
    {
        config()->set('html-translator.http.timeouts', 5);
        $this->app()->instance(HtmlTranslatorServiceProvider::HTTP_CLIENT, new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('Not used.');
            }
        });

        $this->expectExceptionMessage('html-translator.http.timeouts: unknown key');

        $this->manager();
    }

    #[Test]
    public function it_uses_laravel_psr18_client_by_default(): void
    {
        self::assertInstanceOf(LaravelPsr18Client::class, $this->app()->make(HtmlTranslatorServiceProvider::HTTP_CLIENT));
    }

    #[Test]
    public function it_rejects_a_binding_of_the_wrong_type(): void
    {
        $this->app()->instance(HtmlTranslatorServiceProvider::CACHE, new stdClass());

        $this->expectExceptionMessage('The "html-translator.cache" binding must be a ' . CacheItemPoolInterface::class . ', got stdClass.');

        $this->manager();
    }

    private function app(): Application
    {
        return $this->app ?? self::fail('No application.');
    }

    private function manager(): HtmlTranslationManager
    {
        $manager = $this->app()->make(HtmlTranslationManager::class);
        self::assertInstanceOf(HtmlTranslationManager::class, $manager);

        return $manager;
    }
}
