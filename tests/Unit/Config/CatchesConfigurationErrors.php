<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Unit\Config;

use Closure;
use HtmlTranslator\Laravel\Exceptions\InvalidConfigurationException;

/**
 * For assertions on the exception's key and previous exception, which expectExceptionObject() does not compare.
 */
trait CatchesConfigurationErrors
{
    /**
     * @param Closure(): mixed $call
     */
    private function configurationError(Closure $call): InvalidConfigurationException
    {
        try {
            $call();
        } catch (InvalidConfigurationException $exception) {
            return $exception;
        }

        self::fail('Expected an InvalidConfigurationException.');
    }
}
