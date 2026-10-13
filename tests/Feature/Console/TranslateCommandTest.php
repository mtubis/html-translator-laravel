<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature\Console;

use HtmlTranslator\Laravel\Config\ChatModelFactory;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\HtmlRulesFactory;
use HtmlTranslator\Laravel\Config\OptionsFactory;
use HtmlTranslator\Laravel\Config\ProfileFactory;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\Console\TranslateCommand;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Laravel\Facades\HtmlTranslation;
use HtmlTranslator\Laravel\HtmlTranslationManager;
use HtmlTranslator\Laravel\HtmlTranslatorServiceProvider;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use HtmlTranslator\Laravel\PendingTranslation;
use HtmlTranslator\Laravel\Testing\HtmlTranslationFake;
use HtmlTranslator\Laravel\Testing\RecordedTranslation;
use HtmlTranslator\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(TranslateCommand::class)]
#[UsesClass(HtmlTranslatorServiceProvider::class)]
#[UsesClass(HtmlTranslationManager::class)]
#[UsesClass(PendingTranslation::class)]
#[UsesClass(HtmlTranslationFake::class)]
#[UsesClass(RecordedTranslation::class)]
#[UsesClass(HtmlTranslation::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(ProviderFactory::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(ProfileFactory::class)]
#[UsesClass(OptionsFactory::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
#[UsesClass(LaravelPsr18Client::class)]
final class TranslateCommandTest extends TestCase
{
    use RunsCommands;

    private const string HTML = '<h1>Pricing</h1><p>Plans for <b>teams</b></p>';

    private string $directory;

    private string $workingDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/html-translator-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory . '/page.html', self::HTML);
        // Relative paths (and mutants that skip a check) write into the temporary directory, not the project.
        $this->workingDirectory = (string) getcwd();
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        $files = glob($this->directory . '/*');
        array_map(unlink(...), false === $files ? [] : $files);
        rmdir($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function it_prints_one_translation_to_stdout_and_the_summary_to_stderr(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCommand(TranslateCommand::class, ['input' => $this->directory . '/page.html', '--to' => ['de']]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('<b>', $stdout);
        self::assertStringNotContainsString('Pricing', $stdout);
        self::assertStringNotContainsString('units', $stdout);
        self::assertMatchesRegularExpression('/^de: 2 units, 1 batches, 0 cache hits, 0 retries, 0 issues$/m', $stderr);
    }

    #[Test]
    public function it_shows_a_progress_bar_on_a_terminal_and_clears_it(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCommand(TranslateCommand::class, ['input' => $this->directory . '/page.html', '--to' => ['de']], terminal: true);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('1/1 batches', $stderr);
        self::assertStringContainsString('de: 2 units', $stderr);
        self::assertStringNotContainsString('batches [', $stdout);
    }

    /**
     * STDOUT is the result: raw (no console formatting of "<info>" or backslashes) and printed even with -q.
     */
    #[Test]
    public function it_prints_the_html_raw_and_even_when_quiet(): void
    {
        HtmlTranslation::fake(['de' => static fn(string $html): string => '<pre>a \\<b> <info>x</info> <fg=red>y</></pre>']);

        [$exitCode, $stdout] = $this->runCommand(TranslateCommand::class, ['input' => '-', '--to' => ['de']], self::HTML, OutputInterface::VERBOSITY_QUIET);

        self::assertSame(0, $exitCode);
        self::assertSame('<pre>a \\<b> <info>x</info> <fg=red>y</></pre>', $stdout);
    }

    #[Test]
    public function it_keeps_secrets_out_of_its_errors(): void
    {
        config()->set('html-translator.providers.claude', ['driver' => 'anthropic', 'api_key' => 'sk-test-SECRET-0123', 'model' => 'm', 'base_url' => 'ftp://x.test', 'extra_headers' => ['X-Gateway' => 'gw-SECRET-value']]);

        [$exitCode, $stdout, $stderr] = $this->runCommand(TranslateCommand::class, ['input' => '-', '--to' => ['de'], '--provider' => 'claude'], self::HTML);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('html-translator.providers.claude', $stderr);
        self::assertStringNotContainsString('SECRET', $stdout . $stderr);
    }

    #[Test]
    public function it_reads_stdin(): void
    {
        [$exitCode, $stdout] = $this->runCommand(TranslateCommand::class, ['input' => '-', '--to' => ['fr']], '<p>Hello</p>');

        self::assertSame(0, $exitCode);
        self::assertStringStartsWith('<p>', $stdout);
        self::assertStringNotContainsString('Hello', $stdout);
    }

    #[Test]
    public function it_writes_one_file_per_target_next_to_the_input(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCommand(TranslateCommand::class, ['input' => $this->directory . '/page.html', '--to' => ['de', 'pt_br']]);

        self::assertSame(0, $exitCode);
        self::assertSame('', $stdout);
        self::assertFileExists($this->directory . '/page.de.html');
        self::assertFileExists($this->directory . '/page.pt-BR.html');
        self::assertStringContainsString('de → ' . $this->directory . '/page.de.html: 2 units', $stderr);
    }

    #[Test]
    public function it_writes_to_the_output_file_with_or_without_a_locale_placeholder(): void
    {
        $this->runCommand(TranslateCommand::class, ['input' => $this->directory . '/page.html', '--to' => ['de'], '--output' => $this->directory . '/out.html']);
        [$exitCode] = $this->runCommand(TranslateCommand::class, ['input' => '-', '--to' => ['de', 'fr'], '--output' => $this->directory . '/{locale}.html'], self::HTML);

        self::assertSame(0, $exitCode);
        self::assertFileExists($this->directory . '/out.html');
        self::assertFileExists($this->directory . '/de.html');
        self::assertFileExists($this->directory . '/fr.html');
    }

    #[Test]
    public function it_passes_the_options_to_the_translator(): void
    {
        $fake = HtmlTranslation::fake();

        $this->runCommand(TranslateCommand::class, [
            'input' => $this->directory . '/page.html',
            '--to' => ['de'],
            '--from' => 'en',
            '--provider' => 'anthropic',
            '--profile' => 'docs',
            '--fallback' => 'text_nodes_only',
        ]);

        $fake->assertTranslated(static fn(RecordedTranslation $t): bool => 'de' === $t->target && 'en' === $t->source
            && 'anthropic' === $t->provider && 'docs' === $t->profile && self::HTML === $t->html);
    }

    #[Test]
    public function it_fails_and_reports_issues_when_a_result_is_incomplete(): void
    {
        config()->set('html-translator.providers.broken', ['driver' => 'pseudo', 'corrupt_every' => 1, 'corrupt_retries' => true]);

        [$exitCode, , $stderr] = $this->runCommand(
            TranslateCommand::class,
            ['input' => $this->directory . '/page.html', '--to' => ['de'], '--provider' => 'broken'],
            verbosity: OutputInterface::VERBOSITY_VERBOSE,
        );

        self::assertSame(1, $exitCode);
        self::assertMatchesRegularExpression('/^de: 2 units, .*, 2 issues$/m', $stderr);
        self::assertMatchesRegularExpression('/^  \w+ \S+: /m', $stderr);
        self::assertStringContainsString('de is incomplete: 2 issues', (string) preg_replace('/\s+/', ' ', $stderr));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidCalls(): iterable
    {
        yield 'no target' => [['--to' => []], 'Give at least one target locale with --to.'];
        yield 'unknown provider' => [['--to' => ['de'], '--provider' => 'missing'], 'html-translator.providers.missing: is not defined; defined: '];
        yield 'unknown profile' => [['--to' => ['de'], '--profile' => 'missing'], 'html-translator.profiles.missing: is not defined; defined: default'];
        yield 'invalid locale' => [['--to' => ['german']], '"german" is not a valid locale'];
        yield 'invalid fallback' => [['--to' => ['de'], '--fallback' => 'keep'], 'The --fallback option must be one of keep_original, text_nodes_only, throw; got "keep".'];
        yield 'several targets without placeholder' => [['--to' => ['de', 'fr'], '--output' => 'out.html'], 'Several targets need a {locale} placeholder in --output.'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('invalidCalls')]
    public function it_reports_invalid_calls(array $options, string $message): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCommand(TranslateCommand::class, ['input' => $this->directory . '/page.html', ...$options]);

        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString($message, (string) preg_replace('/\s+/', ' ', $stderr));
    }

    #[Test]
    public function it_needs_an_output_pattern_for_several_targets_from_stdin(): void
    {
        [$exitCode, , $stderr] = $this->runCommand(TranslateCommand::class, ['input' => '-', '--to' => ['de', 'fr']], self::HTML);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Several targets from STDIN need --output with a {locale} placeholder.', (string) preg_replace('/\s+/', ' ', $stderr));
    }

    #[Test]
    public function it_reports_a_missing_input_file(): void
    {
        [$exitCode, , $stderr] = $this->runCommand(TranslateCommand::class, ['input' => $this->directory . '/missing.html', '--to' => ['de']]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Cannot read the input file', $stderr);
    }

    #[Test]
    public function it_reports_an_unwritable_output_file(): void
    {
        [$exitCode, , $stderr] = $this->runCommand(TranslateCommand::class, ['input' => $this->directory . '/page.html', '--to' => ['de'], '--output' => $this->directory . '/missing/out.html']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Cannot write', $stderr);
    }
}
