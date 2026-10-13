# 0004. Laravel's HTTP client as the PSR-18 client

- Status: accepted (2026-10-13, after the spike in #3)
- Date: 2026-10-13

## Context
The core adapters need a PSR-18 client and PSR-17 factories (core ADR-0002). In a Laravel app two options exist:
1. **Guzzle directly** (`GuzzleHttp\Client` is PSR-18, `GuzzleHttp\Psr7\HttpFactory` is PSR-17; both ship with Laravel). Zero code, but calls are invisible to `Http::fake()`, Telescope, Pulse and Laravel's `RequestSending`/`ResponseReceived` events.
2. **A small PSR-18 adapter over `Illuminate\Http\Client\Factory`**. Application developers can then fake model calls with `Http::fake()` in their own tests and see them in Telescope — what Laravel developers expect.

## Decision
Implement `Http\LaravelPsr18Client` (PSR-18 over `Illuminate\Http\Client\Factory`) and bind it as the default client for the package's adapters; PSR-17 factories are `GuzzleHttp\Psr7\HttpFactory`. Rules:
- The PSR request is sent as is: method, URI, headers, body. No retries on the Laravel side (the core `Retrier` retries; see config `http.retry`).
- HTTP error statuses are returned as responses, never thrown (PSR-18 semantics). Connection failures become a PSR-18 `NetworkExceptionInterface` that does **not** hold the original Laravel exception or request (they contain headers with the API key — core providers.md, "secrets").
- Timeouts come from `http.timeout` / `http.connect_timeout`.
- Applications may bind their own `Psr\Http\Client\ClientInterface` under the package's binding key to replace it.

The first issue implementing this is a spike: if the adapter cannot meet these rules cleanly (e.g. streaming bodies, exception chains), fall back to option 1 and supersede this ADR.

## Spike result (#3)
The rules are met without workarounds:
- `PendingRequest::withRequestMiddleware()` runs before Laravel's before-sending callbacks, recorder and fake stubs
  (but after global request middleware and Guzzle's default middleware). A
  middleware that returns the core's PSR request replaces the request Laravel built from its options, so the request is
  sent, recorded (`Http::assertSent()`) and announced (`RequestSending`) exactly as the core built it. Timeouts stay in
  the Guzzle options; `withoutRedirecting()` keeps credential headers from being re-sent to another host;
  `http_errors` is forced off, so an application's `Http::globalOptions()` cannot turn statuses into exceptions.
- `ConnectionException`, Laravel's `RequestException` (a transfer that failed after the headers) and Guzzle exceptions
  without a request become `Exceptions\NetworkException`: the message has the URI's user info and query, non-public
  header values and query values removed; there is no previous exception; the request is kept only for `getRequest()`,
  as a `SensitiveParameterValue`.
- The core's `ChatModelContractTestCase` passes for the OpenAI-compatible and Anthropic adapters on top of the client
  and `Http::fake()`.

## Consequences
- What an application adds to every Laravel request (`Http::globalRequestMiddleware()`, `globalOptions()` headers,
  query or auth) is not sent with model calls: the request goes out as the core built it. Transport options (proxy,
  TLS verification, certificates) still apply. A test pins this.
- Laravel's HTTP events, recorded requests and therefore Telescope see the request with its credentials (that is
  their purpose). Telescope hides only `authorization` by default: applications should add
  `Telescope::hideRequestHeaders(['x-api-key', 'api-key', ...])` and never put keys in query strings. To be repeated
  in the README.
- `Http::preventStrayRequests()` throws `StrayRequestException` with the full URI; it is not caught (it is a test
  setup error) and the core adapters send keys in headers, not in the URI.
- `Http::fake()` works for the whole chain; the core's chat-model contract tests can run on top of the adapter.
- One more class to maintain, with a security-relevant exception path that needs explicit tests.
