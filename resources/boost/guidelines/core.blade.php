## mtubis/html-translator-laravel

Translates HTML (CMS content, emails, pages) with an LLM while keeping the markup valid: inline tags stay on the right words; markup, `<code>`, scripts and `translate="no"` content are never sent to the model — only text and translatable attributes such as `alt` and `title`.

### Usage

- Use the `HtmlTranslation` facade, or inject `HtmlTranslator\Laravel\Contracts\TranslatesHtml` (not `HtmlTranslationManager`) so tests can fake it.
- Providers and profiles are named entries in `config/html-translator.php`; pass their names (`provider('anthropic')`, `profile('docs')`) instead of building core objects.
- Target and source locales are strings (`'de'`, `'pt-BR'`); omit `from()` to let the model detect the source language.
- Always check the result: `isComplete()` is false when some units kept their source text; `issues()` says why. The HTML in `html()` is valid either way.
- API keys live in `.env` (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, …); never hard-code them; `config/html-translator.php` reads them with `env()`.
- Configuration errors are `InvalidConfigurationException` naming the full key; run `php artisan html-translator:check` to list all of them.
- Every exception implements `HtmlTranslator\Exception\TranslatorException`.

@verbatim
<code-snippet name="Translate HTML with a named provider and profile" lang="php">
use HtmlTranslator\Laravel\Facades\HtmlTranslation;

$result = HtmlTranslation::provider('anthropic')->profile('docs')->from('en')->translate($html, 'de');

if (! $result->isComplete()) {
    foreach ($result->issues() as $issue) {
        logger()->warning($issue->message, ['unit' => $issue->unitId->toString()]);
    }
}

$translatedHtml = $result->html();
</code-snippet>
@endverbatim

### Testing

- In tests, call `HtmlTranslation::fake()` and use its assertions instead of mocking HTTP or the manager. The fake needs no network and no configuration; it pseudo-localizes the HTML.
- Canned results: `HtmlTranslation::fake(['de' => fn (string $html) => '<p>Hallo</p>'])`; failures: `HtmlTranslation::fake()->failWith(new ProviderException('…'))`.

@verbatim
<code-snippet name="Fake translations in a test" lang="php">
use HtmlTranslator\Laravel\Facades\HtmlTranslation;
use HtmlTranslator\Laravel\Testing\RecordedTranslation;

HtmlTranslation::fake();

// … code under test …

HtmlTranslation::assertTranslatedTo('de');
HtmlTranslation::assertTranslated(fn (RecordedTranslation $t) => $t->profile === 'docs' && str_contains($t->html, 'Pricing'));
HtmlTranslation::assertTranslatedTimes(1);
HtmlTranslation::assertNothingTranslated(); // when nothing may be translated
</code-snippet>
@endverbatim
