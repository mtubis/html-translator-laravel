<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Config;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;
use HtmlTranslator\Profile\Formality;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ConfigReader::class)]
#[CoversClass(InvalidConfigurationException::class)]
final class ConfigReaderTest extends TestCase
{
    use CatchesConfigurationErrors;

    private const string PREFIX = 'html-translator.section';

    private const string SECRET = 'sk-test-SECRET123';

    #[Test]
    public function it_builds_full_keys_from_the_prefix(): void
    {
        self::assertSame('html-translator.section.model', self::reader([])->key('model'));
    }

    #[Test]
    public function it_treats_missing_and_null_values_as_not_set(): void
    {
        $reader = self::reader(['set' => 'x', 'null' => null, 'false' => false, 'empty' => '']);

        self::assertTrue($reader->has('set'));
        self::assertTrue($reader->has('false'));
        self::assertTrue($reader->has('empty'));
        self::assertFalse($reader->has('null'));
        self::assertFalse($reader->has('missing'));
    }

    #[Test]
    public function it_accepts_known_keys(): void
    {
        self::reader(['a' => 1, 'b' => null])->assertKnownKeys(['a', 'b', 'c']);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function it_suggests_a_known_key_for_a_typo(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey(
            'html-translator.section.formalty',
            'unknown key; did you mean "formality"?',
        ));

        self::reader(['formalty' => 'formal'])->assertKnownKeys(['domain', 'formality']);
    }

    #[Test]
    public function it_lists_the_known_keys_when_nothing_is_close(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey(
            'html-translator.section.colour',
            'unknown key; expected one of domain, formality',
        ));

        self::reader(['colour' => 'red'])->assertKnownKeys(['domain', 'formality']);
    }

    #[Test]
    public function it_names_a_numeric_key_of_a_list_given_instead_of_a_map(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey(
            'html-translator.section.0',
            'unknown key; expected one of domain',
        ));

        self::reader(['formal'])->assertKnownKeys(['domain']);
    }

    #[Test]
    public function it_checks_keys_after_a_known_one(): void
    {
        $this->expectExceptionMessage('html-translator.section.formalty: unknown key');

        self::reader(['domain' => 'x', 'formalty' => 'formal'])->assertKnownKeys(['domain', 'formality']);
    }

    #[Test]
    public function it_does_not_suggest_a_value_three_edits_away(): void
    {
        $this->expectExceptionMessage('html-translator.section.abc: unknown key; expected one of abcdef');

        self::reader(['abc' => 1])->assertKnownKeys(['abcdef']);
    }

    #[Test]
    public function it_suggests_the_first_of_equally_close_keys(): void
    {
        $this->expectExceptionMessage('did you mean "domains"?');

        self::reader(['domain' => 1])->assertKnownKeys(['domains', 'domainx']);
    }

    #[Test]
    public function it_does_not_suggest_a_key_more_than_two_edits_away(): void
    {
        $this->expectExceptionMessage('html-translator.section.fmlty: unknown key; expected one of formality');

        self::reader(['fmlty' => 1])->assertKnownKeys(['formality']);
    }

    #[Test]
    public function it_suggests_the_closest_of_several_known_keys(): void
    {
        $this->expectExceptionMessage('did you mean "batch_size"?');

        self::reader(['batch_sizes' => 1])->assertKnownKeys(['batch_sizexy', 'batch_size']);
    }

    #[Test]
    public function it_reads_a_string(): void
    {
        self::assertSame('gpt', self::reader(['model' => 'gpt'])->string('model'));
        self::assertSame('fallback', self::reader([])->string('model', 'fallback'));
        self::assertSame('fallback', self::reader(['model' => null])->string('model', 'fallback'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidStrings(): iterable
    {
        yield 'empty' => ['', 'expected a non-empty string, got ""'];
        yield 'int' => [5, 'expected a non-empty string, got 5'];
        yield 'float' => [1.5, 'expected a non-empty string, got 1.5'];
        yield 'bool' => [true, 'expected a non-empty string, got true'];
        yield 'false' => [false, 'expected a non-empty string, got false'];
        yield 'array' => [['a'], 'expected a non-empty string, got array'];
        yield 'object' => [new stdClass(), 'expected a non-empty string, got stdClass'];
    }

    #[Test]
    #[DataProvider('invalidStrings')]
    public function it_rejects_an_invalid_string(mixed $value, string $problem): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.model', $problem));

        self::reader(['model' => $value])->string('model');
    }

    #[Test]
    public function it_requires_a_string_without_default(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.model', 'is required'));

        self::reader(['model' => null])->string('model');
    }

    #[Test]
    public function it_reads_a_nullable_string(): void
    {
        self::assertSame('x', self::reader(['v' => 'x'])->nullableString('v'));
        self::assertNull(self::reader(['v' => null])->nullableString('v'));
        self::assertNull(self::reader([])->nullableString('v'));
        self::assertNull(self::reader(['v' => ''])->nullableString('v'));
    }

    #[Test]
    public function it_rejects_a_nullable_string_of_another_type(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.v', 'expected a string or null, got 3'));

        self::reader(['v' => 3])->nullableString('v');
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function validInts(): iterable
    {
        yield 'int' => [30, 30];
        yield 'negative int' => [-2, -2];
        yield 'zero' => [0, 0];
        yield 'string' => ['30', 30];
        yield 'negative string' => ['-1', -1];
        yield 'zero string' => ['0', 0];
    }

    #[Test]
    #[DataProvider('validInts')]
    public function it_reads_an_int(mixed $value, int $expected): void
    {
        self::assertSame($expected, self::reader(['n' => $value])->int('n'));
    }

    #[Test]
    public function it_falls_back_to_the_default_int(): void
    {
        self::assertSame(7, self::reader([])->int('n', 7));
        self::assertSame(7, self::reader(['n' => null])->int('n', 7));
    }

    #[Test]
    public function it_requires_an_int_without_default(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.n', 'is required'));

        self::reader([])->int('n');
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidInts(): iterable
    {
        yield 'float' => [1.5, '1.5'];
        yield 'whole float' => [3.0, '3.0'];
        yield 'decimal string' => ['3.0', '"3.0"'];
        yield 'padded string' => [' 3', '" 3"'];
        yield 'plus sign' => ['+3', '"+3"'];
        yield 'leading zero' => ['03', '"03"'];
        yield 'negative zero' => ['-0', '"-0"'];
        yield 'overflow' => ['99999999999999999999', '"99999999999999999999"'];
        yield 'word' => ['thirty', '"thirty"'];
        yield 'empty' => ['', '""'];
        yield 'bool' => [true, 'true'];
        yield 'array' => [[1], 'array'];
    }

    #[Test]
    #[DataProvider('invalidInts')]
    public function it_rejects_an_invalid_int(mixed $value, string $rendered): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.n', 'expected an integer, got ' . $rendered));

        self::reader(['n' => $value])->int('n');
    }

    #[Test]
    public function it_reads_a_non_negative_int(): void
    {
        self::assertSame(0, self::reader(['n' => 0])->nonNegativeInt('n'));
        self::assertSame(2, self::reader(['n' => '2'])->nonNegativeInt('n'));
        self::assertSame(4, self::reader([])->nonNegativeInt('n', 4));
    }

    #[Test]
    public function it_rejects_a_negative_int(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.n', 'expected an integer >= 0, got -1'));

        self::reader(['n' => -1])->nonNegativeInt('n');
    }

    #[Test]
    public function it_names_the_bound_when_a_non_negative_int_has_the_wrong_type(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.n', 'expected an integer >= 0, got "x"'));

        self::reader(['n' => 'x'])->nonNegativeInt('n');
    }

    #[Test]
    public function it_reads_a_positive_int(): void
    {
        self::assertSame(1, self::reader(['n' => 1])->positiveInt('n'));
        self::assertSame(20, self::reader(['n' => '20'])->positiveInt('n'));
        self::assertSame(5, self::reader([])->positiveInt('n', 5));
    }

    #[Test]
    public function it_rejects_zero_as_a_positive_int(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.n', 'expected an integer >= 1, got 0'));

        self::reader(['n' => 0])->positiveInt('n');
    }

    #[Test]
    public function it_requires_a_positive_int_without_default(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.n', 'is required'));

        self::reader([])->positiveInt('n');
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function validBools(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield '"true"' => ['true', true];
        yield '"false"' => ['false', false];
        yield '"1"' => ['1', true];
        yield '"0"' => ['0', false];
    }

    #[Test]
    #[DataProvider('validBools')]
    public function it_reads_a_bool(mixed $value, bool $expected): void
    {
        self::assertSame($expected, self::reader(['b' => $value])->bool('b'));
    }

    #[Test]
    public function it_falls_back_to_the_default_bool(): void
    {
        self::assertTrue(self::reader([])->bool('b', true));
        self::assertFalse(self::reader(['b' => null])->bool('b', false));
    }

    #[Test]
    public function it_requires_a_bool_without_default(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.b', 'is required'));

        self::reader([])->bool('b');
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidBools(): iterable
    {
        yield 'int' => [1, '1'];
        yield 'yes' => ['yes', '"yes"'];
        yield 'uppercase' => ['TRUE', '"TRUE"'];
        yield 'empty' => ['', '""'];
    }

    #[Test]
    #[DataProvider('invalidBools')]
    public function it_rejects_an_invalid_bool(mixed $value, string $rendered): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.b', 'expected a boolean, got ' . $rendered));

        self::reader(['b' => $value])->bool('b');
    }

    #[Test]
    public function it_reads_a_string_list(): void
    {
        self::assertSame(['a', 'b'], self::reader(['l' => ['a', 'b']])->stringList('l'));
        self::assertSame([], self::reader(['l' => []])->stringList('l'));
        self::assertSame([], self::reader(['l' => null])->stringList('l'));
        self::assertSame([], self::reader([])->stringList('l'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidStringLists(): iterable
    {
        yield 'string' => ['a,b', 'expected a list of strings, got "a,b"'];
        yield 'map' => [['x' => 'a'], 'expected a list of strings, got array'];
        yield 'int item' => [['a', 2], 'item 1 must be a non-empty string, got 2'];
        yield 'empty item' => [['', 'b'], 'item 0 must be a non-empty string, got ""'];
    }

    #[Test]
    #[DataProvider('invalidStringLists')]
    public function it_rejects_an_invalid_string_list(mixed $value, string $problem): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.l', $problem));

        self::reader(['l' => $value])->stringList('l');
    }

    #[Test]
    public function it_reads_a_string_map(): void
    {
        self::assertSame(['pull request' => 'pull request'], self::reader(['m' => ['pull request' => 'pull request']])->stringMap('m'));
        self::assertSame(['2024' => 'year', 'a' => 'b'], self::reader(['m' => ['2024' => 'year', 'a' => 'b']])->stringMap('m'));
        self::assertSame([], self::reader(['m' => []])->stringMap('m'));
        self::assertSame([], self::reader(['m' => null])->stringMap('m'));
        self::assertSame([], self::reader([])->stringMap('m'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidStringMaps(): iterable
    {
        yield 'string' => ['a=b', 'expected a map of strings to strings, got "a=b"'];
        yield 'list' => [['a', 'b'], 'expected a map of strings to strings, got a list'];
        yield 'int value' => [['a' => 1], 'the value for "a" must be a non-empty string, got 1'];
        yield 'empty value' => [['a' => ''], 'the value for "a" must be a non-empty string, got ""'];
    }

    #[Test]
    #[DataProvider('invalidStringMaps')]
    public function it_rejects_an_invalid_string_map(mixed $value, string $problem): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.m', $problem));

        self::reader(['m' => $value])->stringMap('m');
    }

    #[Test]
    public function it_reads_an_enum(): void
    {
        self::assertSame(Formality::Informal, self::reader(['f' => 'informal'])->enum('f', Formality::class));
        self::assertSame(Formality::Formal, self::reader([])->enum('f', Formality::class, Formality::Formal));
        self::assertSame(Formality::Formal, self::reader(['f' => null])->enum('f', Formality::class, Formality::Formal));
    }

    #[Test]
    public function it_requires_an_enum_without_default(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.f', 'is required'));

        self::reader([])->enum('f', Formality::class);
    }

    #[Test]
    public function it_suggests_an_enum_value_for_a_typo(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey(
            'html-translator.section.f',
            'expected one of default, formal, informal, got "infromal"; did you mean "informal"?',
        ));

        self::reader(['f' => 'infromal'])->enum('f', Formality::class);
    }

    #[Test]
    public function it_rejects_an_enum_value_of_another_type(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey(
            'html-translator.section.f',
            'expected one of default, formal, informal, got 1',
        ));

        self::reader(['f' => 1])->enum('f', Formality::class);
    }

    #[Test]
    public function it_reads_one_of_the_allowed_values(): void
    {
        self::assertSame('legal', self::reader(['p' => 'legal'])->oneOf('p', ['default', 'legal']));
        self::assertSame('default', self::reader([])->oneOf('p', ['default', 'legal'], 'default'));
    }

    #[Test]
    public function it_rejects_a_value_that_is_not_allowed(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey(
            'html-translator.section.p',
            'expected one of default, legal, got "marketing"',
        ));

        self::reader(['p' => 'marketing'])->oneOf('p', ['default', 'legal']);
    }

    #[Test]
    public function it_compares_allowed_values_case_sensitively(): void
    {
        $this->expectExceptionMessage('got "Legal"; did you mean "legal"?');

        self::reader(['p' => 'Legal'])->oneOf('p', ['default', 'legal']);
    }

    #[Test]
    public function it_requires_one_of_the_allowed_values_without_default(): void
    {
        $this->expectExceptionObject(InvalidConfigurationException::forKey('html-translator.section.p', 'is required'));

        self::reader(['p' => null])->oneOf('p', ['default']);
    }

    #[Test]
    public function it_reads_a_secret(): void
    {
        self::assertSame(self::SECRET, self::reader(['api_key' => self::SECRET])->secret('api_key'));
        self::assertNull(self::reader(['api_key' => null])->secret('api_key'));
        self::assertNull(self::reader(['api_key' => ''])->secret('api_key'));
        self::assertNull(self::reader([])->secret('api_key'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidSecrets(): iterable
    {
        yield 'int' => [12345, 'int'];
        yield 'array' => [[self::SECRET], 'array'];
        yield 'bool' => [true, 'bool'];
    }

    #[Test]
    #[DataProvider('invalidSecrets')]
    public function it_names_only_the_type_of_an_invalid_secret(mixed $value, string $type): void
    {
        try {
            self::reader(['api_key' => $value])->secret('api_key');
            self::fail('Expected an exception.');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame('html-translator.section.api_key: expected a string or null, got ' . $type, $exception->getMessage());
            self::assertStringNotContainsString('12345', $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    #[Test]
    public function it_reads_a_secret_map(): void
    {
        self::assertSame(['X-Token' => self::SECRET, '2024' => ''], self::reader(['h' => ['X-Token' => self::SECRET, '2024' => '']])->secretMap('h'));
        self::assertSame([], self::reader(['h' => []])->secretMap('h'));
        self::assertSame([], self::reader(['h' => null])->secretMap('h'));
        self::assertSame([], self::reader([])->secretMap('h'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidSecretMaps(): iterable
    {
        yield 'string' => [self::SECRET, 'expected a map of names to strings, got string'];
        yield 'list' => [[self::SECRET], 'expected a map of names to strings, got a list'];
        yield 'int value' => [['X-Token' => 12345678], 'the value for "X-Token" must be a string, got int'];
        yield 'array value' => [['X-Token' => [self::SECRET]], 'the value for "X-Token" must be a string, got array'];
    }

    #[Test]
    #[DataProvider('invalidSecretMaps')]
    public function it_names_only_the_types_in_an_invalid_secret_map(mixed $value, string $problem): void
    {
        $exception = $this->configurationError(static fn(): array => self::reader(['h' => $value])->secretMap('h'));

        self::assertSame('html-translator.section.h: ' . $problem, $exception->getMessage());
        self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        self::assertStringNotContainsString('12345678', $exception->getMessage());
    }

    #[Test]
    public function it_reports_core_errors_of_a_whole_entry_under_its_key(): void
    {
        $core = new InvalidArgumentException('An Anthropic configuration needs a model.');

        $exception = $this->configurationError(static fn(): mixed => self::reader([])->guardEntry(static fn(): never => throw $core));

        self::assertSame('html-translator.section: An Anthropic configuration needs a model.', $exception->getMessage());
        self::assertSame('html-translator.section', $exception->key);
        self::assertSame($core, $exception->getPrevious());
    }

    #[Test]
    public function it_returns_the_result_of_a_guarded_entry(): void
    {
        self::assertSame('ok', self::reader([])->guardEntry(static fn(): string => 'ok'));
    }

    #[Test]
    public function it_renders_untrusted_strings_on_one_line(): void
    {
        $this->expectExceptionMessage('got "a\\nb\\u{001B}[31mc\\u{202E}d\\te"');

        self::reader(['p' => "a\nb\e[31mc\u{202E}d\te"])->oneOf('p', ['default']);
    }

    #[Test]
    public function it_truncates_long_strings(): void
    {
        try {
            self::reader(['p' => str_repeat('x', 51)])->oneOf('p', ['default']);
            self::fail('Expected an exception.');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringEndsWith('got "' . str_repeat('x', 49) . '…"', $exception->getMessage());
        }
    }

    #[Test]
    public function it_counts_characters_not_bytes_when_truncating(): void
    {
        $this->expectExceptionMessage('got "' . str_repeat('é', 50) . '"');

        self::reader(['p' => str_repeat('é', 50)])->oneOf('p', ['default']);
    }

    #[Test]
    public function it_truncates_multibyte_strings_from_the_start(): void
    {
        $this->expectExceptionMessage('got "ab' . str_repeat('é', 47) . '…"');

        self::reader(['p' => 'ab' . str_repeat('é', 60)])->oneOf('p', ['default']);
    }

    #[Test]
    public function it_keeps_strings_of_the_maximum_length(): void
    {
        $this->expectExceptionMessage('got "' . str_repeat('x', 50) . '"');

        self::reader(['p' => str_repeat('x', 50)])->oneOf('p', ['default']);
    }

    #[Test]
    public function it_scrubs_invalid_utf8(): void
    {
        $this->expectExceptionMessage("got \"a\u{FFFD}b\"");

        self::reader(['p' => "a\xFFb"])->oneOf('p', ['default']);
    }

    #[Test]
    public function it_converts_core_argument_errors_into_configuration_errors(): void
    {
        $core = new InvalidArgumentException('"x y" is not a valid class name.');

        $exception = $this->configurationError(static fn(): mixed => self::reader([])->guard('skip_classes', static fn(): never => throw $core));

        self::assertSame('html-translator.section.skip_classes: "x y" is not a valid class name.', $exception->getMessage());
        self::assertSame('html-translator.section.skip_classes', $exception->key);
        self::assertSame($core, $exception->getPrevious());
    }

    #[Test]
    public function it_returns_the_guarded_result(): void
    {
        self::assertSame(42, self::reader([])->guard('k', static fn(): int => 42));
    }

    /**
     * @param array<mixed> $values
     */
    private static function reader(array $values): ConfigReader
    {
        return new ConfigReader($values, self::PREFIX);
    }
}
