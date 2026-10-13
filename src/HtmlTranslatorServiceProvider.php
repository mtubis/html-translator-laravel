<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel;

use GuzzleHttp\Psr7\HttpFactory;
use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Laravel\Config\ChatModelFactory;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Symfony\Component\Cache\Exception\InvalidArgumentException as CacheInvalidArgumentException;

/**
 * Registers the package (ARCHITECTURE §5): the only class that reads the configuration and resolves cache stores and
 * log channels. Every binding is resolved lazily, so an application boots even when an unused provider is misconfigured
 * (ADR-0007). The HTTP client, PSR-17 factories, cache pool and logger can be replaced by binding these keys.
 */
final class HtmlTranslatorServiceProvider extends ServiceProvider
{
    public const string CONFIG_KEY = 'html-translator';

    /** Psr\Http\Client\ClientInterface; LaravelPsr18Client by default (ADR-0004). */
    public const string HTTP_CLIENT = 'html-translator.http.client';

    /** Psr\Http\Message\RequestFactoryInterface. */
    public const string REQUEST_FACTORY = 'html-translator.http.request_factory';

    /** Psr\Http\Message\StreamFactoryInterface. */
    public const string STREAM_FACTORY = 'html-translator.http.stream_factory';

    /** Psr\Cache\CacheItemPoolInterface or null (no cache); a Laravel cache store by default (ADR-0005). */
    public const string CACHE = 'html-translator.cache';

    /** Psr\Log\LoggerInterface or null (no logging); a Laravel log channel by default. */
    public const string LOGGER = 'html-translator.logger';

    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), self::CONFIG_KEY);

        $this->app->singleton(self::HTTP_CLIENT, static function (Container $app): ClientInterface {
            $http = self::http($app);

            return new LaravelPsr18Client(
                $app->make(HttpClientFactory::class),
                $http->positiveInt('timeout', 180),
                $http->positiveInt('connect_timeout', 10),
            );
        });
        $this->app->singleton(self::REQUEST_FACTORY, static fn(): RequestFactoryInterface => new HttpFactory());
        $this->app->singleton(self::STREAM_FACTORY, static fn(): StreamFactoryInterface => new HttpFactory());
        $this->app->singleton(self::CACHE, $this->cachePool(...));
        $this->app->singleton(self::LOGGER, $this->logger(...));

        $this->app->singleton(HtmlTranslationManager::class, static function (Container $app): HtmlTranslationManager {
            self::http($app);
            $retry = self::section($app, 'http')['retry'] ?? [];

            return new HtmlTranslationManager(
                self::config($app),
                new ProviderFactory(new ChatModelFactory(
                    self::make($app, self::HTTP_CLIENT, ClientInterface::class),
                    self::make($app, self::REQUEST_FACTORY, RequestFactoryInterface::class),
                    self::make($app, self::STREAM_FACTORY, StreamFactoryInterface::class),
                    ChatModelFactory::retrier(\is_array($retry) ? $retry : throw InvalidConfigurationException::forKey(self::CONFIG_KEY . '.http.retry', 'expected an array, got ' . get_debug_type($retry))),
                )),
                self::nullable($app, self::CACHE, CacheItemPoolInterface::class),
                self::nullable($app, self::LOGGER, LoggerInterface::class),
            );
        });
        $this->app->alias(HtmlTranslationManager::class, TranslatesHtml::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [$this->configPath() => $this->app->configPath(self::CONFIG_KEY . '.php')],
                self::CONFIG_KEY . '-config',
            );
        }
    }

    /**
     * The `http` section, checked for unknown keys also when the application binds its own HTTP client.
     */
    private static function http(Container $app): ConfigReader
    {
        $http = new ConfigReader(self::section($app, 'http'), self::CONFIG_KEY . '.http');
        $http->assertKnownKeys(['timeout', 'connect_timeout', 'retry']);

        return $http;
    }

    private function cachePool(Container $app): ?CacheItemPoolInterface
    {
        $cache = new ConfigReader(self::section($app, 'cache'), self::CONFIG_KEY . '.cache');
        $cache->assertKnownKeys(['enabled', 'store', 'prefix', 'ttl']);
        if (!$cache->bool('enabled', true)) {
            return null;
        }

        try {
            $store = $app->make(CacheFactory::class)->store($cache->nullableString('store'));
        } catch (InvalidArgumentException $exception) {
            throw InvalidConfigurationException::forKey(self::CONFIG_KEY . '.cache.store', $exception->getMessage(), $exception);
        }
        $prefix = $cache->string('prefix', self::CONFIG_KEY);
        $ttl = $cache->has('ttl') ? $cache->positiveInt('ttl') : 0;

        try {
            return new Psr16Adapter($store, $prefix, $ttl);
        } catch (CacheInvalidArgumentException $exception) {
            throw InvalidConfigurationException::forKey(self::CONFIG_KEY . '.cache.prefix', $exception->getMessage(), $exception);
        }
    }

    private function logger(Container $app): ?LoggerInterface
    {
        $channel = self::config($app)['log_channel'] ?? null;
        if (false === $channel) {
            return null;
        }

        if (null !== $channel && (!\is_string($channel) || '' === $channel)) {
            throw InvalidConfigurationException::forKey(self::CONFIG_KEY . '.log_channel', 'expected a channel name, null (the default channel) or false (no logging), got ' . get_debug_type($channel));
        }

        // Laravel falls back to its emergency logger for an unknown channel; the package's issues would get lost.
        $channels = $app->make(ConfigRepository::class)->get('logging.channels');
        if (null !== $channel && \is_array($channels) && !\array_key_exists($channel, $channels)) {
            throw InvalidConfigurationException::forKey(
                self::CONFIG_KEY . '.log_channel',
                \sprintf('the channel "%s" is not defined in config/logging.php; defined: %s', $channel, implode(', ', array_keys($channels))),
            );
        }

        return $app->make(LogManager::class)->channel($channel);
    }

    /**
     * @return array<mixed>
     */
    private static function config(Container $app): array
    {
        $config = $app->make(ConfigRepository::class)->get(self::CONFIG_KEY);

        return \is_array($config) ? $config : [];
    }

    /**
     * @return array<mixed>
     */
    private static function section(Container $app, string $section): array
    {
        $value = self::config($app)[$section] ?? [];
        if (!\is_array($value)) {
            throw InvalidConfigurationException::forKey(self::CONFIG_KEY . '.' . $section, 'expected an array, got ' . get_debug_type($value));
        }

        return $value;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    private static function make(Container $app, string $key, string $type): object
    {
        $service = $app->make($key);
        if (!$service instanceof $type) {
            throw new LogicException(\sprintf('The "%s" binding must be a %s, got %s.', $key, $type, get_debug_type($service)));
        }

        return $service;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T|null
     */
    private static function nullable(Container $app, string $key, string $type): ?object
    {
        $service = $app->make($key);

        return null === $service ? null : self::make($app, $key, $type);
    }

    private function configPath(): string
    {
        return __DIR__ . '/../config/' . self::CONFIG_KEY . '.php';
    }
}
