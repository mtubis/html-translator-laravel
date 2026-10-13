## mtubis/html-translator-laravel

Translates HTML (CMS content, emails, pages) with an LLM while keeping the markup valid: inline tags stay on the right words, attributes, `<code>`, scripts and `translate="no"` content are never sent to the model.

<!-- Draft. Completed and tested in issue "README, Boost guidelines, integration tests and release 0.1.0". -->

### Usage

- Use the `HtmlTranslation` facade (or inject `HtmlTranslator\Laravel\HtmlTranslationManager`); providers and profiles are named entries in `config/html-translator.php`.
- Always check the result: `isComplete()` is false when some units kept their source text; `issues()` says why.
- API keys live in `.env` (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, …); never hard-code them.
- In tests, call `HtmlTranslation::fake()` and use its assertions instead of mocking HTTP.

@verbatim
<code-snippet name="Translate HTML with a named provider and profile" lang="php">
use HtmlTranslator\Laravel\Facades\HtmlTranslation;

$result = HtmlTranslation::provider('anthropic')->profile('docs')->from('en')->translate($html, 'de');

if (! $result->isComplete()) {
    foreach ($result->issues() as $issue) {
        logger()->warning($issue->message);
    }
}

$translatedHtml = $result->html();
</code-snippet>
@endverbatim
