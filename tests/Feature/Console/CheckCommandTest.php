<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature\Console;

use HtmlTranslator\Laravel\Config\ChatModelFactory;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\HtmlRulesFactory;
use HtmlTranslator\Laravel\Config\OptionsFactory;
use HtmlTranslator\Laravel\Config\ProfileFactory;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\Console\CheckCommand;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Laravel\HtmlTranslationManager;
use HtmlTranslator\Laravel\HtmlTranslatorServiceProvider;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use HtmlTranslator\Laravel\PendingTranslation;
use HtmlTranslator\Laravel\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

#[CoversClass(CheckCommand::class)]
#[UsesClass(HtmlTranslatorServiceProvider::class)]
#[UsesClass(HtmlTranslationManager::class)]
#[UsesClass(PendingTranslation::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(ProviderFactory::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(ProfileFactory::class)]
#[UsesClass(OptionsFactory::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
#[UsesClass(LaravelPsr18Client::class)]
final class CheckCommandTest extends TestCase
{
    use RunsCommands;

    private const string KEY = 'sk-test-SECRET-0123456789';

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        // Only entries that are valid without environment variables.
        $app['config']->set('html-translator.providers', ['pseudo' => ['driver' => 'pseudo'], 'local' => ['driver' => 'ollama', 'model' => 'llama-test']]);
        $app['config']->set('html-translator.profiles', ['default' => ['preset' => 'default'], 'docs' => ['preset' => 'technical']]);
    }

    #[Test]
    public function it_accepts_a_valid_configuration(): void
    {
        Http::preventStrayRequests();

        [$exitCode, $stdout] = $this->runCommand(CheckCommand::class, []);

        self::assertSame(0, $exitCode, $stdout);
        foreach (['providers.pseudo', 'providers.local', 'profiles.default', 'profiles.docs', 'options'] as $label) {
            self::assertMatchesRegularExpression('/' . preg_quote($label, '/') . ' \.* OK/', $stdout);
        }

        self::assertStringContainsString('The configuration is valid.', $stdout);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_lists_every_problem_in_one_run_without_secrets(): void
    {
        config()->set('html-translator.providers.openai', ['driver' => 'openai', 'api_key' => self::KEY, 'model' => null]);
        config()->set('html-translator.providers.claude', ['driver' => 'anthropic', 'api_key' => self::KEY, 'model' => 'm', 'base_url' => 'ftp://' . self::KEY . '@x.test']);
        config()->set('html-translator.profiles.blog', ['formality' => 'casual']);
        config()->set('html-translator.options.batch_size', 0);

        [$exitCode, $stdout] = $this->runCommand(CheckCommand::class, []);

        self::assertSame(1, $exitCode);
        self::assertMatchesRegularExpression('/providers\.openai \.* FAIL/', $stdout);
        self::assertStringContainsString('html-translator.providers.openai.model: is required; the shipped configuration reads it from HTML_TRANSLATOR_OPENAI_MODEL', $stdout);
        self::assertMatchesRegularExpression('/providers\.claude \.* FAIL/', $stdout);
        self::assertStringContainsString('html-translator.providers.claude: The Anthropic base URL', $stdout);
        self::assertStringContainsString('html-translator.profiles.blog.formality: expected one of default, formal, informal, got "casual"', $stdout);
        self::assertStringContainsString('html-translator.options.batch_size: expected an integer >= 1, got 0', $stdout);
        self::assertMatchesRegularExpression('/providers\.pseudo \.* OK/', $stdout);
        self::assertMatchesRegularExpression('/profiles\.default \.* OK/', $stdout);
        self::assertMatchesRegularExpression('/^  html-translator\.options\.batch_size: /m', $stdout);
        self::assertStringContainsString('4 problems found.', $stdout);
        self::assertStringNotContainsString(self::KEY, $stdout);
    }

    #[Test]
    public function it_reports_an_undefined_default_provider(): void
    {
        config()->set('html-translator.default', 'nope');

        [$exitCode, $stdout] = $this->runCommand(CheckCommand::class, []);

        self::assertSame(1, $exitCode);
        self::assertMatchesRegularExpression('/default \.* FAIL/', $stdout);
        self::assertStringContainsString('html-translator.default: "nope" is not a provider entry; defined: pseudo, local', $stdout);
        self::assertStringContainsString('1 problem found.', $stdout);
    }

    #[Test]
    public function it_reports_unexpected_ping_errors_without_details_and_goes_on(): void
    {
        Http::fake(['localhost:11434/*' => static fn(): never => throw new RuntimeException('Request details with a SECRET')]);

        [$exitCode, $stdout] = $this->runCommand(CheckCommand::class, ['--ping' => true]);

        self::assertSame(1, $exitCode);
        self::assertMatchesRegularExpression('/ping local \.* FAIL/', $stdout);
        self::assertStringContainsString('Unexpected RuntimeException', $stdout);
        self::assertStringNotContainsString('SECRET', $stdout);
        self::assertStringContainsString('1 problem found.', $stdout);
    }

    #[Test]
    public function it_checks_only_the_selected_providers(): void
    {
        config()->set('html-translator.providers.broken', ['driver' => 'openai']);

        [$exitCode, $stdout] = $this->runCommand(CheckCommand::class, ['--provider' => ['pseudo']]);

        self::assertSame(0, $exitCode);
        self::assertStringNotContainsString('providers.broken', $stdout);
        self::assertStringNotContainsString('providers.local', $stdout);
    }

    #[Test]
    public function it_reports_invalid_settings_before_the_entries(): void
    {
        config()->set('html-translator.cache.ttl', 0);

        [$exitCode, $stdout] = $this->runCommand(CheckCommand::class, []);

        self::assertSame(1, $exitCode);
        self::assertMatchesRegularExpression('/settings \.* FAIL/', $stdout);
        self::assertStringContainsString('html-translator.cache.ttl: expected an integer >= 1, got 0', $stdout);
    }

    #[Test]
    public function it_pings_every_working_provider(): void
    {
        Http::fake(['localhost:11434/*' => Factory::response(['error' => ['message' => 'model not found']], 404)]);

        [$exitCode, $stdout] = $this->runCommand(CheckCommand::class, ['--ping' => true]);

        self::assertSame(1, $exitCode);
        self::assertMatchesRegularExpression('/ping pseudo \.* OK/', $stdout);
        self::assertMatchesRegularExpression('/ping local \.* FAIL/', $stdout);
        self::assertStringContainsString('404', $stdout);
        self::assertStringContainsString('1 problem found.', $stdout);
    }
}
