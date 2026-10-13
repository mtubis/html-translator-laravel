<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Config;

use GuzzleHttp\Psr7\HttpFactory;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;
use HtmlTranslator\Laravel\Config\ChatModelFactory;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\HtmlRulesFactory;
use HtmlTranslator\Laravel\Config\OptionsFactory;
use HtmlTranslator\Laravel\Config\ProfileFactory;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\TermPolicy;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\Bridge\RetryPolicy;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\TranslationOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * The shipped config/html-translator.php, including its commented examples, must build without errors.
 */
#[CoversClass(ProfileFactory::class)]
#[CoversClass(OptionsFactory::class)]
#[CoversClass(HtmlRulesFactory::class)]
#[CoversClass(ChatModelFactory::class)]
#[CoversClass(ProviderFactory::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class DefaultConfigTest extends TestCase
{
    use CatchesConfigurationErrors;

    private const string CONFIG_FILE = __DIR__ . '/../../../config/html-translator.php';

    #[Test]
    public function it_builds_the_shipped_sections(): void
    {
        $config = $this->section(require self::CONFIG_FILE);

        self::assertEquals(TranslationProfile::default(), ProfileFactory::fromArray('default', $this->section($this->section($config['profiles'] ?? null)['default'] ?? null)));
        self::assertEquals(new TranslationOptions(), OptionsFactory::fromArray($this->section($config['options'] ?? null)));
        self::assertEquals(SkipRules::defaults(), HtmlRulesFactory::skipRules($this->section($config['html'] ?? null)));
        self::assertEquals(TranslatableAttributes::defaults(), HtmlRulesFactory::translatableAttributes($this->section($config['html'] ?? null)));
    }

    #[Test]
    public function it_builds_the_commented_docs_profile(): void
    {
        $profile = ProfileFactory::fromArray('docs', $this->commentedExample('docs'));

        self::assertSame(Formality::Informal, $profile->formality);
        self::assertSame(TermPolicy::PreferOriginalTechnicalTerms, $profile->termPolicy);
        self::assertSame('Developer documentation of a PHP framework', $profile->domain);
        self::assertSame(['Laravel', 'Eloquent'], $profile->doNotTranslate);
        self::assertCount(1, $profile->glossary->entries());
    }

    #[Test]
    public function it_builds_the_shipped_retry_policy_and_pseudo_provider(): void
    {
        $config = $this->section(require self::CONFIG_FILE);
        $providers = $this->section($config['providers'] ?? null);

        self::assertEquals(new RetryPolicy(), ChatModelFactory::retryPolicy($this->section($this->section($config['http'] ?? null)['retry'] ?? null)));
        self::assertEquals(new PseudoLocalizationProvider(), new ProviderFactory(self::chatModels())->fromArray('pseudo', $this->section($providers['pseudo'] ?? null)));
    }

    /**
     * The "is required" messages name the env variables the shipped entries really read.
     */
    #[Test]
    public function it_names_the_env_variables_the_shipped_provider_entries_read(): void
    {
        $config = $this->section(require self::CONFIG_FILE);
        $providers = $this->section($config['providers'] ?? null);
        $source = (string) file_get_contents(self::CONFIG_FILE);
        $checked = 0;

        foreach (['openai', 'anthropic', 'gemini', 'ollama'] as $name) {
            preg_match(\sprintf("/^        '%s' => \\[\\n(.*?)^        \\],/ms", $name), $source, $block);
            $entry = $this->section($providers[$name] ?? null);

            foreach (['api_key', 'model'] as $key) {
                if (1 !== preg_match(\sprintf("/'%s' => env\\('([A-Z0-9_]+)'/", $key), $block[1] ?? '', $env)) {
                    continue;
                }

                // Valid values for the other required keys, so only $key is missing whatever the environment holds.
                $values = array_intersect_key(['api_key' => 'sk-test', 'model' => 'm'], $entry);
                $exception = $this->configurationError(static fn(): mixed => self::chatModels()->config($name, [...$entry, ...$values, $key => null]));

                self::assertSame(
                    \sprintf('html-translator.providers.%s.%s: is required; the shipped configuration reads it from %s', $name, $key, $env[1]),
                    $exception->getMessage(),
                );
                ++$checked;
            }
        }

        self::assertSame(7, $checked);
    }

    #[Test]
    public function it_builds_the_commented_together_provider(): void
    {
        $config = self::chatModels()->config('together', $this->commentedExample('together'));

        self::assertSame('together:meta-llama/Llama-3.3-70B-Instruct-Turbo', $config->modelId());
    }

    /**
     * Turns a commented entry such as `// 'docs' => [ … // ],` back into PHP and evaluates it.
     *
     * @return array<mixed>
     */
    private function commentedExample(string $name): array
    {
        $source = (string) file_get_contents(self::CONFIG_FILE);
        $pattern = \sprintf("/^[ \\t]*\\/\\/ '%s' => \\[\\n.*?^[ \\t]*\\/\\/ \\],\\n/ms", preg_quote($name, '/'));
        preg_match($pattern, $source, $match);
        $block = $match[0] ?? self::fail(\sprintf('No commented "%s" example in the config file.', $name));

        $code = (string) preg_replace('/^[ \t]*\/\/ ?/m', '', $block);
        $file = (string) tempnam(sys_get_temp_dir(), 'html-translator-config');
        file_put_contents($file, "<?php\n\nreturn [\n" . $code . "];\n");

        try {
            $example = $this->section(require $file);
        } finally {
            unlink($file);
        }

        return $this->section($example[$name] ?? null);
    }

    private static function chatModels(): ChatModelFactory
    {
        $psr17 = new HttpFactory();

        return new ChatModelFactory(
            new class implements ClientInterface {
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException('No HTTP in this test.');
                }
            },
            $psr17,
            $psr17,
            new Retrier(),
        );
    }

    /**
     * @return array<mixed>
     */
    private function section(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
