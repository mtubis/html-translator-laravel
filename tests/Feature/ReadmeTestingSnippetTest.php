<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A plain PHPUnit test (not Testbench): it only starts a process.
 *
 * Runs the "Testing your application" example of the README as a real PHPUnit test, in a separate process, so the
 * documented example cannot rot: the first snippet is the application code, the second the test methods.
 */
#[CoversNothing]
final class ReadmeTestingSnippetTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    #[Test]
    public function it_runs_the_testing_example_of_the_readme(): void
    {
        [$application, $test] = $this->snippets();
        // PHPUnit expects the file to be named after its test class.
        $directory = sys_get_temp_dir() . '/readme-testing-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $file = $directory . '/TranslatePageTest.php';
        file_put_contents($file, $this->exampleFile($application, $test));

        try {
            $process = new Process(
                [\PHP_BINARY, 'vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'vendor/autoload.php', '--do-not-record-test-run-history', '--fail-on-deprecation', '--fail-on-warning', '--fail-on-notice', '--fail-on-risky', $file],
                self::ROOT,
                // An application's environment: none of this package's test settings (e.g. the pseudo default provider).
                ['APP_KEY' => 'base64:AckfSECXIvnK5r28GVIWUAxmbBSjTsmF8SN3HTWlKD4=', 'CACHE_STORE' => 'array', 'HTML_TRANSLATOR_PROVIDER' => false],
            );
            $process->run();
        } finally {
            unlink($file);
            rmdir($directory);
        }

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertMatchesRegularExpression('/^OK \(2 tests, /m', $process->getOutput());
    }

    /**
     * @return array{string, string} the application code and the test methods
     */
    private function snippets(): array
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');
        $start = strpos($readme, "## Testing your application\n");
        self::assertNotFalse($start, 'The README has no "Testing your application" section.');
        $end = strpos($readme, "\n## ", $start + 1);
        $section = substr($readme, $start, false === $end ? null : $end - $start);

        preg_match_all('/```php\n(.*?)```/s', $section, $matches);
        self::assertCount(2, $matches[1], 'The section must have two PHP snippets: the application code and the test.');

        return [$matches[1][0], $matches[1][1]];
    }

    /**
     * Both snippets in one file: the application code in its namespace, the test methods in a Testbench test class.
     */
    private function exampleFile(string $application, string $test): string
    {
        preg_match('/^namespace ([^;]+);$/m', $application, $namespace);
        self::assertArrayHasKey(1, $namespace, 'The application snippet must declare its namespace.');
        $application = (string) preg_replace('/^namespace [^;]+;$/m', '', $application);

        preg_match_all('/^use [^;]+;$/m', $test, $uses);
        $methods = (string) preg_replace('/^use [^;]+;$/m', '', $test);

        return "<?php\n\nnamespace {$namespace[1]} {\n{$application}\n}\n\n"
            . "namespace ReadmeSnippet {\n" . implode("\n", $uses[0]) . "\n\n"
            . "final class TranslatePageTest extends \\HtmlTranslator\\Laravel\\Tests\\TestCase\n{\n{$methods}\n}\n}\n";
    }
}
