<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Tests\Feature\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a command with its own STDIN and separate STDOUT and STDERR: Laravel's artisan() test helper offers neither,
 * and the translate command must keep STDOUT free of anything but HTML.
 */
trait RunsCommands
{
    /**
     * @param class-string<Command> $command
     * @param array<string, mixed> $parameters
     *
     * @return array{int, string, string} exit code, STDOUT, STDERR
     */
    private function runCommand(string $command, array $parameters, ?string $stdin = null, int $verbosity = OutputInterface::VERBOSITY_NORMAL, bool $terminal = false): array
    {
        $app = $this->app;
        self::assertInstanceOf(Application::class, $app);
        $instance = $app->make($command);
        self::assertInstanceOf(Command::class, $instance);
        $instance->setLaravel($app);

        $input = new ArrayInput($parameters);
        if (null !== $stdin) {
            $stream = fopen('php://memory', 'r+');
            self::assertIsResource($stream);
            fwrite($stream, $stdin);
            rewind($stream);
            $input->setStream($stream);
        }

        $output = new SplitOutput($verbosity, $terminal);
        $exitCode = $instance->run($input, $output);

        return [$exitCode, $output->stdout(), $output->stderr()];
    }
}
