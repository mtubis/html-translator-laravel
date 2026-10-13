# 0004. Laravel's HTTP client as the PSR-18 client

- Status: proposed
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

## Consequences
- `Http::fake()` works for the whole chain; the core's chat-model contract tests can run on top of the adapter.
- One more class to maintain, with a security-relevant exception path that needs explicit tests.
