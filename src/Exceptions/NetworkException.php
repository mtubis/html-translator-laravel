<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Exceptions;

use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Exception\TranslatorException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * The HTTP client could not reach the server (ADR-0004).
 *
 * PSR-18 requires getRequest(), but the request carries the credential headers: it is kept as a
 * SensitiveParameterValue, so it stays out of var_dump(), print_r() and var_export(), and serialize() fails (do not
 * put the exception in a queue payload or cache). The message is redacted by the client and there is no previous
 * exception (Laravel's would hold the request again).
 */
final class NetworkException extends RuntimeException implements NetworkExceptionInterface, TranslatorException
{
    private readonly SensitiveParameterValue $request;

    public function __construct(string $message, #[SensitiveParameter] RequestInterface $request)
    {
        parent::__construct($message);
        $this->request = new SensitiveParameterValue($request);
    }

    public function getRequest(): RequestInterface
    {
        $request = $this->request->getValue();

        return $request instanceof RequestInterface ? $request : throw new LogicException('The request of a NetworkException is missing.');
    }
}
