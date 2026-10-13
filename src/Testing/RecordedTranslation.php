<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Testing;

use HtmlTranslator\TranslatedDocument;

/**
 * One translation recorded by HtmlTranslation::fake(), for assertions in application tests.
 */
final readonly class RecordedTranslation
{
    public function __construct(
        /** The HTML that was given to translate. */
        public string $html,
        /** The normalized target locale, e.g. "de" or "pt-BR". */
        public string $target,
        /** The normalized source locale; null when none was given (the model would detect it). */
        public ?string $source,
        /** The provider entry; null for the default provider. */
        public ?string $provider,
        /** The profile entry, the fingerprint of a core profile, or null for the default profile. */
        public ?string $profile,
        /** The fake's result; null when the translation threw (failWith(), or a canned response that throws). */
        public ?TranslatedDocument $result,
    ) {}
}
