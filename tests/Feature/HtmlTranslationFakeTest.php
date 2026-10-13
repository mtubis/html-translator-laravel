<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature;

use Closure;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Laravel\Config\ChatModelFactory;
use HtmlTranslator\Laravel\Config\ConfigReader;
use HtmlTranslator\Laravel\Config\OptionsFactory;
use HtmlTranslator\Laravel\Config\ProviderFactory;
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;
use HtmlTranslator\Laravel\Facades\HtmlTranslation;
use HtmlTranslator\Laravel\HtmlTranslationManager;
use HtmlTranslator\Laravel\HtmlTranslatorServiceProvider;
use HtmlTranslator\Laravel\Http\LaravelPsr18Client;
use HtmlTranslator\Laravel\PendingTranslation;
use HtmlTranslator\Laravel\Testing\HtmlTranslationFake;
use HtmlTranslator\Laravel\Testing\RecordedTranslation;
use HtmlTranslator\Laravel\Tests\TestCase;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Progress;
use HtmlTranslator\TranslationOptions;
use HtmlTranslator\TranslationStats;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use ReflectionClass;

#[CoversClass(HtmlTranslationFake::class)]
#[CoversClass(RecordedTranslation::class)]
#[CoversClass(HtmlTranslation::class)]
#[UsesClass(PendingTranslation::class)]
#[UsesClass(OptionsFactory::class)]
#[UsesClass(ConfigReader::class)]
#[UsesClass(HtmlTranslatorServiceProvider::class)]
#[UsesClass(HtmlTranslationManager::class)]
#[UsesClass(ProviderFactory::class)]
#[UsesClass(ChatModelFactory::class)]
#[UsesClass(LaravelPsr18Client::class)]
final class HtmlTranslationFakeTest extends TestCase
{
    private const string HTML = '<h1>Pricing</h1><p>Plans for <b>teams</b></p>';

    #[Test]
    public function it_swaps_the_facade_root_and_the_contract_binding(): void
    {
        $fake = HtmlTranslation::fake();

        self::assertSame($fake, HtmlTranslation::getFacadeRoot());
        self::assertSame($fake, $this->app()->make(TranslatesHtml::class));
        self::assertInstanceOf(HtmlTranslationManager::class, $this->app()->make(HtmlTranslationManager::class));

        $injected = $this->app()->make(TranslatesHtml::class);
        $injected->provider('openai')->profile('docs')->translate(self::HTML, 'de');
        self::assertSame('openai', self::recordedAt($fake, 0)->provider);
        self::assertSame('docs', self::recordedAt($fake, 0)->profile);
    }

    /**
     * The fake never touches the manager: a broken configuration and a missing key do not matter.
     */
    #[Test]
    public function it_needs_no_configuration(): void
    {
        config()->set('html-translator.default', 'openai');
        config()->set('html-translator.providers.openai', ['driver' => 'openai']);
        config()->set('html-translator.http.retry', 'broken');
        Http::preventStrayRequests();
        $fake = HtmlTranslation::fake();

        self::assertTrue(HtmlTranslation::translate(self::HTML, 'de')->isComplete());
        $fake->assertTranslatedTimes(1);
    }

    #[Test]
    public function it_rejects_overrides_combined_with_core_options_like_the_manager(): void
    {
        HtmlTranslation::fake();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('withOptions() replaces the configured options and every override');

        HtmlTranslation::withOptions(new TranslationOptions())->from('en')->translate(self::HTML, 'de');
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidResponses(): iterable
    {
        yield 'string' => [['de' => '<p>Hallo</p>'], 'HtmlTranslation::fake(): the response for "de" must be a Closure(string $html): string, got string.'];
        yield 'locale' => [['german' => static fn(string $html): string => $html], 'HtmlTranslation::fake(): "german" is not a valid locale'];
    }

    /**
     * @param array<mixed> $responses
     */
    #[Test]
    #[DataProvider('invalidResponses')]
    public function it_rejects_invalid_canned_responses_up_front(array $responses, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        // Through reflection: the types are wrong on purpose, the runtime check is what is tested.
        new ReflectionClass(HtmlTranslationFake::class)->newInstance($responses);
    }

    #[Test]
    public function it_pseudo_localizes_without_network(): void
    {
        Http::preventStrayRequests();
        HtmlTranslation::fake();

        $document = HtmlTranslation::translate(self::HTML, 'de');

        self::assertTrue($document->isComplete());
        self::assertStringNotContainsString('Pricing', $document->html());
        self::assertStringContainsString('<b>', $document->html());
    }

    #[Test]
    public function it_records_what_a_pending_translation_was_configured_with(): void
    {
        $fake = HtmlTranslation::fake();
        $legal = TranslationProfile::legal();

        HtmlTranslation::provider('anthropic')->profile('docs')->from('en')->translate(self::HTML, 'pt_br');
        HtmlTranslation::profile($legal)->translate(self::HTML, Locale::fromString('fr'));
        HtmlTranslation::translate(self::HTML, 'de');

        $first = $this->recordedAt($fake, 0);
        $second = $this->recordedAt($fake, 1);
        $third = $this->recordedAt($fake, 2);
        self::assertEquals(new RecordedTranslation(self::HTML, 'pt-BR', 'en', 'anthropic', 'docs', $first->result), $first);
        self::assertSame($legal->fingerprint(), $second->profile);
        self::assertSame('fr', $second->target);
        self::assertNull($third->provider);
        self::assertNull($third->profile);
        self::assertNull($third->source);
        self::assertNotNull($third->result);
    }

    #[Test]
    public function it_records_the_source_and_profile_of_core_options(): void
    {
        $fake = HtmlTranslation::fake();
        $options = new TranslationOptions(source: Locale::fromString('en'), profile: TranslationProfile::technical());

        HtmlTranslation::withOptions($options)->translate(self::HTML, 'de');

        self::assertSame('en', $this->recordedAt($fake, 0)->source);
        self::assertSame(TranslationProfile::technical()->fingerprint(), $this->recordedAt($fake, 0)->profile);
    }

    #[Test]
    public function it_answers_from_canned_responses_per_locale(): void
    {
        HtmlTranslation::fake([
            'de' => static fn(string $html): string => '<h1>Preise</h1>',
            'pt_BR' => static fn(string $html): string => '<h1>Preços</h1>',
            '*' => strtoupper(...),
        ]);

        self::assertSame('<h1>Preise</h1>', HtmlTranslation::translate(self::HTML, 'de')->html());
        self::assertSame('<h1>Preços</h1>', HtmlTranslation::translate(self::HTML, 'pt-BR')->html());
        self::assertSame(strtoupper(self::HTML), HtmlTranslation::translate(self::HTML, 'fr')->html());
        self::assertTrue(HtmlTranslation::translate(self::HTML, 'de')->isComplete());
        self::assertEquals(new TranslationStats(0, 0, 0, 0.0), HtmlTranslation::translate(self::HTML, 'de')->stats());
    }

    #[Test]
    public function it_pseudo_localizes_targets_without_a_canned_response(): void
    {
        HtmlTranslation::fake(['de' => static fn(string $html): string => '<p>Hallo</p>']);

        self::assertStringNotContainsString('Pricing', HtmlTranslation::translate(self::HTML, 'fr')->html());
    }

    #[Test]
    public function it_fails_every_following_translation_when_asked_and_still_records_it(): void
    {
        $failure = new ProviderException('The model is overloaded.');
        $fake = HtmlTranslation::fake()->failWith($failure);

        try {
            HtmlTranslation::translate(self::HTML, 'de');
            self::fail('Expected the failure.');
        } catch (ProviderException $exception) {
            self::assertSame($failure, $exception);
        }

        $fake->assertTranslatedTimes(1);
        self::assertNull($this->recordedAt($fake, 0)->result);
    }

    #[Test]
    public function it_applies_the_overrides_to_the_pseudo_localization(): void
    {
        HtmlTranslation::fake();
        $batches = [];

        HtmlTranslation::from('en')
            ->fallback(FallbackStrategy::TextNodesOnly)
            ->maxCorrectiveRetries(0)
            ->tokenBudget(500)
            ->batchSize(1)
            ->onProgress(static function (Progress $progress) use (&$batches): void {
                $batches[] = $progress->batchesDone;
            })
            ->translate(self::HTML, 'de');

        self::assertSame([1, 2], $batches);
    }

    #[Test]
    public function it_uses_given_core_options_for_the_pseudo_localization(): void
    {
        HtmlTranslation::fake();
        $batches = 0;

        HtmlTranslation::withOptions(new TranslationOptions(batchSize: 1, onProgress: static function () use (&$batches): void {
            ++$batches;
        }))->translate(self::HTML, 'de');

        self::assertSame(2, $batches);
    }

    #[Test]
    public function it_returns_a_pseudo_localizing_core_translator(): void
    {
        $fake = HtmlTranslation::fake();

        self::assertStringNotContainsString('Pricing', $fake->translator('openai')->translate(self::HTML, Locale::fromString('de'))->html());
        $fake->assertNothingTranslated();
        self::assertSame($fake->translator(), $fake->translator('openai'));
    }

    #[Test]
    public function it_filters_recorded_translations(): void
    {
        $fake = HtmlTranslation::fake();
        HtmlTranslation::translate(self::HTML, 'de');
        HtmlTranslation::translate(self::HTML, 'fr');

        self::assertCount(2, $fake->recorded());
        self::assertSame(['fr'], array_map(static fn(RecordedTranslation $t): string => $t->target, $fake->recorded(static fn(RecordedTranslation $t): bool => 'fr' === $t->target)));
    }

    #[Test]
    public function its_assertions_pass_for_matching_translations(): void
    {
        HtmlTranslation::fake();
        HtmlTranslation::translate(self::HTML, 'de');
        HtmlTranslation::translate(self::HTML, 'pt-BR');

        HtmlTranslation::assertTranslated();
        HtmlTranslation::assertTranslated(static fn(RecordedTranslation $t): bool => 'de' === $t->target && str_contains($t->html, 'Pricing'));
        HtmlTranslation::assertTranslatedTo('pt_br');
        HtmlTranslation::assertNotTranslated(static fn(RecordedTranslation $t): bool => 'fr' === $t->target);
        HtmlTranslation::assertTranslatedTimes(2);
    }

    #[Test]
    public function its_negative_assertions_pass_when_nothing_was_translated(): void
    {
        HtmlTranslation::fake();

        HtmlTranslation::assertNothingTranslated();
        HtmlTranslation::assertNotTranslated();
        HtmlTranslation::assertTranslatedTimes(0);
    }

    /**
     * @return iterable<string, array{list<string>, Closure(HtmlTranslationFake): void, non-empty-string}>
     */
    public static function failingAssertions(): iterable
    {
        yield 'translated, nothing translated' => [[], static fn(HtmlTranslationFake $f) => $f->assertTranslated(), 'Expected HTML to be translated; nothing was translated.'];
        yield 'translated, no match' => [
            ['de', 'fr'],
            static fn(HtmlTranslationFake $f) => $f->assertTranslated(static fn(RecordedTranslation $t): bool => 'pl' === $t->target),
            'No recorded translation matches the callback (2 translations recorded, to de, fr).',
        ];
        yield 'translated to, other locales' => [['fr', 'pl'], static fn(HtmlTranslationFake $f) => $f->assertTranslatedTo('de'), 'Expected a translation to de; 2 translations recorded, to fr, pl.'];
        yield 'translated to, nothing' => [[], static fn(HtmlTranslationFake $f) => $f->assertTranslatedTo('pt_br'), 'Expected a translation to pt-BR; nothing was translated.'];
        yield 'not translated, match' => [
            ['de', 'fr'],
            static fn(HtmlTranslationFake $f) => $f->assertNotTranslated(static fn(RecordedTranslation $t): bool => 'de' === $t->target),
            'Unexpected translation matching the callback: 1 of 2 translations recorded, to de, fr.',
        ];
        yield 'not translated without callback' => [['de'], static fn(HtmlTranslationFake $f) => $f->assertNotTranslated(), 'Expected nothing to be translated; 1 translation recorded, to de.'];
        yield 'times' => [['de'], static fn(HtmlTranslationFake $f) => $f->assertTranslatedTimes(2), 'Expected 2 translations; got 1.'];
        yield 'times, one' => [['de', 'fr'], static fn(HtmlTranslationFake $f) => $f->assertTranslatedTimes(1), 'Expected 1 translation; got 2.'];
        yield 'nothing translated' => [['de', 'fr'], static fn(HtmlTranslationFake $f) => $f->assertNothingTranslated(), 'Expected nothing to be translated; 2 translations recorded, to de, fr.'];
    }

    /**
     * @param list<string> $targets
     * @param Closure(HtmlTranslationFake): void $assertion
     * @param non-empty-string $message
     */
    #[Test]
    #[DataProvider('failingAssertions')]
    public function its_assertions_fail_with_a_readable_message(array $targets, Closure $assertion, string $message): void
    {
        $fake = HtmlTranslation::fake();
        foreach ($targets as $target) {
            HtmlTranslation::translate(self::HTML, $target);
        }

        try {
            $assertion($fake);
        } catch (AssertionFailedError $failure) {
            self::assertStringStartsWith($message, $failure->getMessage());

            return;
        }

        self::fail('The assertion passed.');
    }

    private function recordedAt(HtmlTranslationFake $fake, int $index): RecordedTranslation
    {
        return $fake->recorded()[$index] ?? self::fail(\sprintf('No recorded translation #%d.', $index));
    }

    private function app(): Application
    {
        return $this->app ?? self::fail('No application.');
    }
}
