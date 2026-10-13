<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature;

use HtmlTranslator\Laravel\Config\ChatModelFactory;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\HtmlRulesFactory;
use HtmlTranslator\Laravel\Config\OptionsFactory;
use HtmlTranslator\Laravel\Config\ProfileFactory;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;
use HtmlTranslator\Laravel\Facades\HtmlTranslation;
use HtmlTranslator\Laravel\HtmlTranslationManager;
use HtmlTranslator\Laravel\HtmlTranslatorServiceProvider;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use HtmlTranslator\Laravel\PendingTranslation;
use HtmlTranslator\Laravel\Testing\HtmlTranslationFake;
use HtmlTranslator\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

#[CoversClass(HtmlTranslation::class)]
#[UsesClass(HtmlTranslationManager::class)]
#[UsesClass(PendingTranslation::class)]
#[UsesClass(HtmlTranslatorServiceProvider::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(ProviderFactory::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(ProfileFactory::class)]
#[UsesClass(OptionsFactory::class)]
#[UsesClass(HtmlRulesFactory::class)]
#[UsesClass(LaravelPsr18Client::class)]
final class HtmlTranslationFacadeTest extends TestCase
{
    #[Test]
    public function it_translates_through_the_facade(): void
    {
        self::assertTrue(HtmlTranslation::translate('<p>Hello</p>', 'de')->isComplete());
        self::assertTrue(HtmlTranslation::provider('pseudo')->from('en')->translate('<p>Hello</p>', 'de')->isComplete());
        self::assertSame($this->app?->make(HtmlTranslationManager::class), HtmlTranslation::getFacadeRoot());
    }

    /**
     * Every public method of the facade's surface is documented with the same signature, so IDEs and PHPStan see it.
     */
    #[Test]
    public function it_documents_every_public_method_with_its_signature(): void
    {
        $documented = $this->documentedMethods();
        $expected = [];
        // The contract (manager and fake) and the fake's testing helpers, which the facade forwards after fake().
        $methods = [
            ...new ReflectionClass(TranslatesHtml::class)->getMethods(ReflectionMethod::IS_PUBLIC),
            ...new ReflectionClass(HtmlTranslationFake::class)->getMethods(ReflectionMethod::IS_PUBLIC),
        ];
        foreach ($methods as $method) {
            if (\in_array($method->getName(), ['translatePending', '__construct'], true)) {
                continue;
            }

            $expected[$method->getName()] = $this->signature($method);
        }

        ksort($documented);
        ksort($expected);
        self::assertSame($expected, $documented);
    }

    #[Test]
    public function it_exposes_nothing_beyond_the_contract(): void
    {
        $manager = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            new ReflectionClass(HtmlTranslationManager::class)->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        $contract = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            new ReflectionClass(TranslatesHtml::class)->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        // options(), namedProfile(), providerNames() and profileNames() are @internal (tests, the fake, html-translator:check); the
        // constructor and __debugInfo() are not part of the facade.
        self::assertSame([], array_values(array_diff($manager, $contract, ['__construct', '__debugInfo', 'options', 'namedProfile', 'defaultProvider', 'providerNames', 'profileNames'])));
    }

    /**
     * @return array<string, string> method name → "returnType(paramType $param, …)"
     */
    private function documentedMethods(): array
    {
        $doc = (string) new ReflectionClass(HtmlTranslation::class)->getDocComment();
        preg_match_all('/@method static (\S+) (\w+)\(([^)]*)\)/', $doc, $matches, \PREG_SET_ORDER);

        $methods = [];
        foreach ($matches as [, $return, $name, $parameters]) {
            $methods[$name] = self::normalize($return) . '(' . implode(', ', array_map(
                static fn(string $parameter): string => self::normalize(trim(preg_replace('/\s*=\s*/', ' = ', $parameter) ?? $parameter)),
                '' === trim($parameters) ? [] : explode(',', $parameters),
            )) . ')';
        }

        return $methods;
    }

    private function signature(ReflectionMethod $method): string
    {
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $default = $parameter->isDefaultValueAvailable() ? ' = ' . strtolower(var_export($parameter->getDefaultValue(), true)) : '';
            $parameters[] = self::normalize(self::type($parameter->getType()) . ' $' . $parameter->getName() . $default);
        }

        $return = self::type($method->getReturnType());

        return self::normalize('self' === $return ? $method->getDeclaringClass()->getName() : $return) . '(' . implode(', ', $parameters) . ')';
    }

    private static function type(?ReflectionType $type): string
    {
        return match (true) {
            $type instanceof ReflectionNamedType => ($type->allowsNull() && 'mixed' !== $type->getName() && 'null' !== $type->getName() ? 'null|' : '') . $type->getName(),
            $type instanceof ReflectionUnionType => implode('|', array_map(self::type(...), $type->getTypes())),
            default => 'mixed',
        };
    }

    /**
     * Sorted union members, "?T" as "null|T", short class names: "\X\A|string|null $x" equals "?A|string $x".
     */
    private static function normalize(string $signature): string
    {
        $words = explode(' ', trim($signature), 2);
        $types = $words[0];
        $name = $words[1] ?? '';
        // Generic PHPDoc types are their native type: "list<RecordedTranslation>" is "array".
        $types = str_replace('?', 'null|', (string) preg_replace(['/<[^>]*>/', '/\blist\b/'], ['', 'array'], ltrim($types, '\\')));
        // Short class names: the docblock uses imported names, reflection fully qualified ones.
        $parts = array_unique(array_map(static fn(string $part): string => substr($part, (int) strrpos('\\' . $part, '\\')), explode('|', $types)));
        sort($parts);

        return trim(implode('|', $parts) . ' ' . $name);
    }
}
