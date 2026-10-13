<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Http;

use GuzzleHttp\Exception\GuzzleException;
use HtmlTranslator\Laravel\Exceptions\NetworkException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\HttpClientException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

/**
 * PSR-18 over Laravel's HTTP client (ADR-0004), so Http::fake(), Telescope and Laravel's HTTP events see model calls.
 *
 * The request goes out exactly as the core built it, error statuses come back as responses, nothing is retried or
 * redirected here (the core Retrier retries). A connection failure becomes a NetworkException whose message has the
 * request's credentials removed and which keeps neither Laravel's exception nor, outside its getRequest(), the request.
 */
final readonly class LaravelPsr18Client implements ClientInterface
{
    private const string REDACTED = '[redacted]';

    /** Shorter values are not removed from messages: they are rarely secrets and would garble the text. */
    private const int MIN_REDACTED_LENGTH = 8;

    /** Headers whose values are not secrets and are useful in a message (e.g. the host that cannot be resolved). */
    private const array PUBLIC_HEADERS = ['host', 'content-type', 'content-length', 'accept', 'accept-encoding', 'user-agent', 'connection', 'anthropic-version', 'anthropic-beta'];

    public function __construct(
        private Factory $http,
        private int|float $timeout,
        private int|float $connectTimeout,
    ) {}

    public function sendRequest(#[SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->http->createPendingRequest()
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->withoutRedirecting()
                // An application's Http::globalOptions() could turn error statuses into exceptions; PSR-18 returns them.
                ->withOptions(['http_errors' => false])
                // Laravel builds its own request from options; this middleware runs before Laravel's recorder, fakes
                // and events and puts the core's request back, so it is sent and seen unchanged.
                ->withRequestMiddleware(static fn(): RequestInterface => $request)
                ->send($request->getMethod(), (string) $request->getUri());
        } catch (HttpClientException|GuzzleException $exception) {
            // Laravel reports a failed connection as ConnectionException, a transfer that failed after the response
            // headers as RequestException, and rethrows Guzzle exceptions that carry no request.
            // Built here, not in a helper: this frame's request argument is sensitive, a helper's would be recorded.
            throw new NetworkException($this->redact($exception->getMessage(), $request), $request);
        }

        return $response->toPsrResponse();
    }

    /**
     * Removes what can carry a credential: the user info and query of the URI, values of non-public headers (also
     * each word of them, for "Bearer <key>") and query values.
     */
    private function redact(string $message, #[SensitiveParameter] RequestInterface $request): string
    {
        $uri = $request->getUri();
        $message = str_replace((string) $uri, (string) $uri->withUserInfo('')->withQuery('')->withFragment(''), $message);
        // Guzzle quotes URIs with the user info masked ("https://***@host/…?key=…"), so the exact URI is not always
        // there: drop the query of every URL in the message.
        $message = preg_replace('~(https?://[^\s?#]+)\?[^\s#)\]]*~i', '$1', $message) ?? $message;

        $secrets = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (\in_array(strtolower((string) $name), self::PUBLIC_HEADERS, true)) {
                continue;
            }

            foreach ($values as $value) {
                $value = str_replace("\t", ' ', $value);
                array_push($secrets, $value, ...explode(' ', $value));
            }
        }

        // Query values as sent (percent-encoded) and decoded.
        foreach (explode('&', $uri->getQuery()) as $pair) {
            $value = explode('=', $pair, 2)[1] ?? '';
            $secrets[] = $value;
            $secrets[] = urldecode($value);
        }

        $password = explode(':', $uri->getUserInfo(), 2)[1] ?? '';
        $secrets[] = $password;
        $secrets[] = rawurldecode($password);

        $secrets = array_filter($secrets, static fn(string $secret): bool => \strlen($secret) >= self::MIN_REDACTED_LENGTH);
        // Longest first, so a secret containing another one is removed whole.
        usort($secrets, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return str_replace($secrets, self::REDACTED, $message);
    }
}
