# ChatModelFactory and ProviderFactory for every driver

## Context
ARCHITECTURE §5, ADR-0007, core `docs/providers.md` (presets, configuration parameters, retries). Use the `add-provider-driver` skill.

## Scope
- `Config\ChatModelFactory` — provider entry → core chat model:
  - `openai` → `OpenAiCompatibleConfig::openAi($key, $model, $jsonMode)`; `gemini`, `ollama`, `vllm`, `lm_studio` → their presets; `openai_compatible` → `new OpenAiCompatibleConfig(...)`;
  - `anthropic` → `new AnthropicConfig(...)` (`effort`, `base_url`, `refusal_fallbacks`, `betas`, `extra_headers`);
  - `http.retry` → `new Retrier(new RetryPolicy(...))`;
  - PSR-18 client and PSR-17 factories injected into the factory (not resolved inside it).
- `Config\ProviderFactory` — `pseudo` → `PseudoLocalizationProvider`; LLM drivers → `LlmTranslationProvider` around the chat model; unknown driver → `InvalidConfigurationException` listing the known drivers.
- `laravel_ai` is recognised as a known, not-yet-implemented driver with a clear message ("planned for 0.3").

## Acceptance criteria
- [ ] Table-driven test per driver: entry → expected core config values (inspect via the configs' public properties, never the secret).
- [ ] Missing `model` / `api_key` produce messages that name the env variable from the default config (e.g. "set OPENAI_API_KEY").
- [ ] An `Http::fake()` feature test per LLM driver translates `<p>Hello</p>` end-to-end with a canned vendor response.
- [ ] `composer check` green.
