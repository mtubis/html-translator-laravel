<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature;

use HtmlTranslator\Laravel\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs every README code block marked `<!-- runnable -->`, so the documentation cannot rot. Each block runs in its own
 * scope with $html given; every provider entry it names is the offline pseudo provider.
 */
#[CoversNothing]
final class ReadmeSnippetsTest extends TestCase
{
    private const string README = __DIR__ . '/../../README.md';

    private const string HTML = '<h1>Getting started</h1><p>Install the <strong>package</strong> first.</p>';

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        foreach (['openai', 'anthropic', 'gemini', 'ollama'] as $name) {
            $app['config']->set('html-translator.providers.' . $name, ['driver' => 'pseudo']);
        }

        $app['config']->set('html-translator.profiles.docs', ['preset' => 'technical']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function snippets(): iterable
    {
        preg_match_all('/^<!-- runnable -->\n```php\n(.*?)^```$/ms', (string) file_get_contents(self::README), $matches);

        foreach ($matches[1] as $index => $code) {
            yield \sprintf('#%d %s', $index + 1, strtok($code, "\n")) => [$code];
        }
    }

    #[Test]
    public function it_finds_a_php_block_after_every_runnable_marker(): void
    {
        $markers = substr_count((string) file_get_contents(self::README), '<!-- runnable -->');

        self::assertSame(5, $markers);
        self::assertCount($markers, iterator_to_array(self::snippets()));
    }

    #[Test]
    #[DataProvider('snippets')]
    public function it_runs_a_readme_snippet(string $code): void
    {
        Http::preventStrayRequests();
        $file = (string) tempnam(sys_get_temp_dir(), 'readme-snippet');
        file_put_contents($file, "<?php\n\n" . $code);

        try {
            (static function (string $html) use ($file): void {
                require $file;
            })(self::HTML);
        } finally {
            unlink($file);
        }

        Http::assertNothingSent();
        $this->addToAssertionCount(1);
    }
}
