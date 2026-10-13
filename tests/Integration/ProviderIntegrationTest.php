<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Integration;

use Closure;
use HtmlTranslator\Issue;
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;
use HtmlTranslator\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * One real translation per LLM driver (`composer test:integration`; costs a few tokens). Keys and models come from
 * environment variables, e.g. `set -a; . ./.env; set +a` first (see .env.dist and CONTRIBUTING.md); a driver whose
 * variables are missing is skipped.
 */
#[Group('integration')]
#[CoversNothing]
final class ProviderIntegrationTest extends TestCase
{
    private const string HTML = '<p>Hello <b>world</b>, how are you today?</p>';

    /**
     * @return iterable<string, array{list<string>, Closure(array<string, string>): array<string, mixed>}>
     */
    public static function drivers(): iterable
    {
        yield 'openai' => [['OPENAI_API_KEY', 'HTML_TRANSLATOR_OPENAI_MODEL'], static fn(array $env): array => ['driver' => 'openai', 'api_key' => $env['OPENAI_API_KEY'] ?? null, 'model' => $env['HTML_TRANSLATOR_OPENAI_MODEL'] ?? null]];
        yield 'anthropic' => [['ANTHROPIC_API_KEY', 'HTML_TRANSLATOR_ANTHROPIC_MODEL'], static fn(array $env): array => ['driver' => 'anthropic', 'api_key' => $env['ANTHROPIC_API_KEY'] ?? null, 'model' => $env['HTML_TRANSLATOR_ANTHROPIC_MODEL'] ?? null]];
        yield 'gemini' => [['GEMINI_API_KEY', 'HTML_TRANSLATOR_GEMINI_MODEL'], static fn(array $env): array => ['driver' => 'gemini', 'api_key' => $env['GEMINI_API_KEY'] ?? null, 'model' => $env['HTML_TRANSLATOR_GEMINI_MODEL'] ?? null]];
        yield 'ollama' => [['HTML_TRANSLATOR_OLLAMA_MODEL'], static fn(array $env): array => ['driver' => 'ollama', 'model' => $env['HTML_TRANSLATOR_OLLAMA_MODEL'] ?? null, 'base_url' => self::env('OLLAMA_BASE_URL')]];
    }

    /**
     * @param list<string> $variables
     * @param Closure(array<string, string>): array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('drivers')]
    public function it_translates_with_a_real_model(array $variables, Closure $entry): void
    {
        $values = [];
        foreach ($variables as $variable) {
            $values[$variable] = self::env($variable) ?? self::markTestSkipped(\sprintf('Set %s in .env to run this test.', $variable));
        }

        config()->set('html-translator.providers.integration', $entry($values));
        config()->set('html-translator.cache.enabled', false);
        $translator = $this->app?->make(TranslatesHtml::class);
        self::assertInstanceOf(TranslatesHtml::class, $translator);

        $document = $translator->provider('integration')->from('en')->translate(self::HTML, 'de');

        self::assertTrue($document->isComplete(), implode("\n", array_map(static fn(Issue $issue): string => $issue->message, $document->issues())));
        self::assertStringContainsString('<b>', $document->html());
        self::assertStringNotContainsString('how are you', $document->html());
        self::assertGreaterThan(0, $document->stats()->batches);
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
