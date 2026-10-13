<?php

declare(strict_types=1);

/*
 * Configuration of mtubis/html-translator-laravel.
 *
 * This file is the specification the package is built against (docs/ARCHITECTURE.md §4). Every key is validated when
 * a translator is first resolved; a wrong value fails with InvalidConfigurationException naming the key.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    | The key of the entry in "providers" used when no provider is named, e.g. HtmlTranslation::translate($html, 'pl').
    */
    'default' => env('HTML_TRANSLATOR_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | Each entry is a named provider. "driver" picks the core adapter:
    |
    |   openai, gemini, ollama, vllm, lm_studio → OpenAiCompatibleConfig presets
    |   openai_compatible                       → new OpenAiCompatibleConfig(...) for any other server
    |   anthropic                               → AnthropicConfig
    |   laravel_ai                              → a provider configured in config/ai.php (laravel/ai, planned)
    |   pseudo                                  → PseudoLocalizationProvider, no network (local development, tests)
    |
    | Several entries may use the same driver, e.g. a cheap and a strong model. Optional keys per driver:
    |
    |   ollama, lm_studio  base_url              vllm  base_url, api_key
    |   openai             json_mode             anthropic  api_version, default_max_output_tokens, extra_headers
    |   openai_compatible  see the commented "together" example
    |
    | Unknown keys are reported as errors, with a suggestion for typos.
    */
    'providers' => [

        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('HTML_TRANSLATOR_OPENAI_MODEL'),
            'json_mode' => 'json_schema', // none | json_object | json_schema | json_schema_only
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('HTML_TRANSLATOR_ANTHROPIC_MODEL'),
            'effort' => env('HTML_TRANSLATOR_ANTHROPIC_EFFORT'), // null | low | medium | high | xhigh | max
            'base_url' => env('ANTHROPIC_BASE_URL'),              // null = https://api.anthropic.com/v1
            'refusal_fallbacks' => false,
            'betas' => [],
            'extra_headers' => [], // e.g. a gateway token; treated as secret
        ],

        'gemini' => [
            'driver' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('HTML_TRANSLATOR_GEMINI_MODEL'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'model' => env('HTML_TRANSLATOR_OLLAMA_MODEL'),
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
        ],

        // 'together' => [
        //     'driver' => 'openai_compatible',
        //     'name' => 'together',
        //     'base_url' => 'https://api.together.xyz/v1',
        //     'api_key' => env('TOGETHER_API_KEY'),
        //     'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
        //     'json_mode' => 'json_object',
        //     'max_tokens_field' => 'max_tokens',
        //     'extra_headers' => [],
        // ],

        'pseudo' => [
            'driver' => 'pseudo',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    | Requests go through Laravel's HTTP client (Http::fake() works in your tests). Translation batches can take a
    | while, especially with higher reasoning effort: keep the timeout generous. "retry" maps to the core RetryPolicy;
    | do not configure retries on the HTTP client as well. Requests are sent exactly as built: Http::globalOptions()
    | headers and global request middleware do not apply to them. Telescope records them with their headers; hide
    | "x-api-key", "api-key" and the names of your extra_headers there (Telescope::hideRequestHeaders()).
    */
    'http' => [
        'timeout' => (int) env('HTML_TRANSLATOR_HTTP_TIMEOUT', 180),
        'connect_timeout' => 10,
        'retry' => [
            'max_attempts' => 3,
            'base_delay_ms' => 1000,
            'max_delay_ms' => 30000,
            'retry_network_errors' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Translation profiles
    |--------------------------------------------------------------------------
    | Named profiles steer how text is translated. "preset" is the starting point (default, technical, marketing,
    | legal); the other keys override it. term_policy: translate_all | prefer_original_technical_terms | keep_original.
    | formality: default | formal | informal.
    */
    'default_profile' => 'default',

    'profiles' => [

        'default' => [
            'preset' => 'default',
        ],

        // 'docs' => [
        //     'preset' => 'technical',
        //     'formality' => 'informal',
        //     'term_policy' => 'prefer_original_technical_terms',
        //     'domain' => 'Developer documentation of a PHP framework',
        //     'glossary' => ['pull request' => 'pull request'],
        //     'do_not_translate' => ['Laravel', 'Eloquent'],
        //     'instructions' => null,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Translation options
    |--------------------------------------------------------------------------
    | fallback: keep_original | text_nodes_only | throw — what happens to a unit that still breaks the markup after
    | the corrective retries.
    */
    'options' => [
        'source' => null, // null = the model detects the source language
        'fallback' => 'keep_original',
        'max_corrective_retries' => 2,
        'batch_size' => 20,
        'token_budget' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | What is translated
    |--------------------------------------------------------------------------
    | Additions to the core defaults (code, pre, script, translate="no", class="notranslate", alt, title, …).
    */
    'html' => [
        'skip_elements' => [],          // e.g. ['address']
        'skip_classes' => [],           // e.g. ['brand']
        'translatable_attributes' => [],// e.g. ['data-tooltip']
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    | Translated units are cached per text, locale pair, profile and model, so re-translating an edited page only pays
    | for the changed paragraphs. "store" is a store from config/cache.php (null = the default store).
    */
    'cache' => [
        'enabled' => (bool) env('HTML_TRANSLATOR_CACHE', true),
        'store' => env('HTML_TRANSLATOR_CACHE_STORE'),
        'prefix' => 'html-translator',
        'ttl' => null, // seconds, null = forever
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    | A channel from config/logging.php; null = the default channel, false = no logging.
    */
    'log_channel' => env('HTML_TRANSLATOR_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Queue (Laravel 0.2)
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('HTML_TRANSLATOR_QUEUE_CONNECTION'),
        'queue' => env('HTML_TRANSLATOR_QUEUE'),
    ],
];
