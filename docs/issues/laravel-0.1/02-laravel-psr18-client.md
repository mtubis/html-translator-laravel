# LaravelPsr18Client: PSR-18 over Laravel's HTTP client (spike)

## Context
ADR-0004 (status: proposed) — read its rules carefully, especially the security rule about exception chains. Core `docs/providers.md` ("The API key and extra header values are treated as secrets") and the core's `ChatModelContractTestCase` (`ChatScenarioKind::NetworkError`) define what "safe" means. Read them in `vendor/mtubis/html-translator/`.

## Scope
- `Http\LaravelPsr18Client implements Psr\Http\Client\ClientInterface` over `Illuminate\Http\Client\Factory` with timeout and connect timeout from config.
- Sends method, URI, all headers and the body unchanged; returns the PSR-7 response (`toPsrResponse()`) for every status; never retries.
- Connection failures → package `NetworkException implements Psr\Http\Client\NetworkExceptionInterface` that returns the PSR request from `getRequest()` as PSR-18 requires, but whose message is redacted and whose `getPrevious()` is null (no Laravel exception, no request in the chain).
- Contract tests: run the core's `ChatModelContractTestCase` for `OpenAiCompatibleChatModel` and `AnthropicChatModel` with this client on top of `Http::fake()`.
- If the rules cannot be met cleanly: stop, document why, propose superseding ADR-0004 with Guzzle (`GuzzleHttp\Client`).

## Acceptance criteria
- [ ] `Http::fake()` / `Http::assertSent()` see the exact request the core adapter built (method, URL, headers, JSON body).
- [ ] 4xx/5xx come back as responses; the core `Retrier` sees 429/529 and retries.
- [ ] A connection error yields a `NetworkExceptionInterface`; a test asserts that neither the message, `getPrevious()` chain nor `getTrace()` arguments contain the API key.
- [ ] Both core contract test cases pass on top of the client.
- [ ] ADR-0004 status updated to accepted (or superseded).
- [ ] `composer check` green.
