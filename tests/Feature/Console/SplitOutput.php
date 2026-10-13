<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature\Console;

use LogicException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * A console output with STDOUT and STDERR in separate memory buffers.
 */
final class SplitOutput extends StreamOutput implements ConsoleOutputInterface
{
    private readonly StreamOutput $error;

    public function __construct(int $verbosity = self::VERBOSITY_NORMAL, bool $decorated = false)
    {
        parent::__construct($this->buffer(), $verbosity, $decorated);
        $this->error = new StreamOutput($this->buffer(), $verbosity, $decorated);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->error;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        throw new LogicException('The error output of a SplitOutput is fixed.');
    }

    public function section(): ConsoleSectionOutput
    {
        throw new LogicException('Sections are not supported.');
    }

    public function stdout(): string
    {
        return $this->read($this);
    }

    public function stderr(): string
    {
        return $this->read($this->error);
    }

    /**
     * @return resource
     */
    private function buffer()
    {
        $stream = fopen('php://memory', 'r+');

        return false === $stream ? throw new LogicException('Cannot open a memory stream.') : $stream;
    }

    private function read(StreamOutput $output): string
    {
        $stream = $output->getStream();
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
