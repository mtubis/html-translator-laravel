<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature;

use HtmlTranslator\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Laravel Boost renders the guidelines with Blade: they must compile, and the code samples must come out verbatim.
 */
#[CoversNothing]
final class BoostGuidelinesTest extends TestCase
{
    private const string GUIDELINES = __DIR__ . '/../../resources/boost/guidelines/core.blade.php';

    #[Test]
    public function it_renders_the_guidelines_with_verbatim_code(): void
    {
        $rendered = Blade::render((string) file_get_contents(self::GUIDELINES));

        self::assertStringStartsWith('## mtubis/html-translator-laravel', $rendered);
        self::assertSame(2, substr_count($rendered, '<code-snippet '));
        self::assertStringContainsString("->translate(\$html, 'de');", $rendered);
        self::assertStringContainsString('fn (RecordedTranslation $t) =>', $rendered);
        self::assertStringContainsString('HtmlTranslation::fake()', $rendered);
        self::assertStringNotContainsString('@verbatim', $rendered);
        self::assertStringNotContainsString('<?php', $rendered);
    }
}
