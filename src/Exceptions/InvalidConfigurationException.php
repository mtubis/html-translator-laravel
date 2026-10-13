<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Exceptions;

use HtmlTranslator\Exception\TranslatorException;
use InvalidArgumentException;
use Throwable;

/**
 * A value in config/html-translator.php is missing, has the wrong type or is not allowed (ADR-0007).
 *
 * The message starts with the full key, so it can be found in the configuration; it never contains a secret.
 */
final class InvalidConfigurationException extends InvalidArgumentException implements TranslatorException
{
    private function __construct(
        public readonly string $key,
        string $message,
        ?Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function forKey(string $key, string $problem, ?Throwable $previous = null): self
    {
        return new self($key, $key . ': ' . $problem, $previous);
    }
}
