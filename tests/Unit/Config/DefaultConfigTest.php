<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Config;

use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\HtmlRulesFactory;
use HtmlTranslator\Laravel\Config\OptionsFactory;
use HtmlTranslator\Laravel\Config\ProfileFactory;
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\TermPolicy;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\TranslationOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The shipped config/html-translator.php, including its commented examples, must build without errors.
 */
#[CoversClass(ProfileFactory::class)]
#[CoversClass(OptionsFactory::class)]
#[CoversClass(HtmlRulesFactory::class)]
#[UsesClass(ConfigReader::class)]
final class DefaultConfigTest extends TestCase
{
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

    /**
     * @return array<mixed>
     */
    private function section(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
