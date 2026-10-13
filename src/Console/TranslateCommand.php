<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Console;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\TranslatorException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Laravel\Contracts\TranslatesHtml;
use HtmlTranslator\Laravel\PendingTranslation;
use HtmlTranslator\Progress;
use HtmlTranslator\TranslatedDocument;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Translates an HTML file (or STDIN) into one or more locales. Translated HTML goes to STDOUT or files; progress, the
 * summary and errors go to STDERR, so the HTML can be piped.
 */
#[AsCommand(name: 'html-translator:translate', description: 'Translate an HTML file into one or more locales')]
final class TranslateCommand extends Command
{
    private const array FALLBACKS = [
        'keep_original' => FallbackStrategy::KeepOriginal,
        'text_nodes_only' => FallbackStrategy::TextNodesOnly,
        'throw' => FallbackStrategy::Throw,
    ];

    /**
     * @return list<InputArgument>
     */
    protected function getArguments(): array
    {
        return [new InputArgument('input', InputArgument::REQUIRED, 'An HTML file, or - to read STDIN')];
    }

    /**
     * Defined here rather than in a signature string, whose parser would take "{locale}" in a description for an
     * argument.
     *
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('to', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target locale, e.g. --to=de; repeat for several'),
            new InputOption('from', null, InputOption::VALUE_REQUIRED, 'Source locale; the model detects it when omitted'),
            new InputOption('provider', null, InputOption::VALUE_REQUIRED, 'A provider entry of config/html-translator.php'),
            new InputOption('profile', null, InputOption::VALUE_REQUIRED, 'A profile entry of config/html-translator.php'),
            new InputOption('output', null, InputOption::VALUE_REQUIRED, 'Output file, may contain {locale}; default: STDOUT for one target, page.{locale}.html for several'),
            new InputOption('fallback', null, InputOption::VALUE_REQUIRED, 'keep_original, text_nodes_only or throw'),
        ];
    }

    public function handle(Container $container): int
    {
        $errors = $this->output->getErrorStyle();
        $targets = $this->targets();
        $argument = $this->argument('input');
        $input = \is_string($argument) ? $argument : '-';
        $output = $this->stringOption('output');
        $fallback = $this->stringOption('fallback');

        $problem = match (true) {
            [] === $targets => 'Give at least one target locale with --to.',
            null !== $fallback && !\array_key_exists($fallback, self::FALLBACKS) => \sprintf('The --fallback option must be one of %s; got "%s".', implode(', ', array_keys(self::FALLBACKS)), $fallback),
            \count($targets) > 1 && null === $output && '-' === $input => 'Several targets from STDIN need --output with a {locale} placeholder.',
            \count($targets) > 1 && null !== $output && !str_contains($output, '{locale}') => 'Several targets need a {locale} placeholder in --output.',
            default => null,
        };
        $html = null === $problem ? $this->read($input) : null;
        if (null === $html) {
            $errors->error($problem ?? \sprintf('Cannot read the input file "%s".', $input));

            return self::FAILURE;
        }

        try {
            $pending = $this->pending($container->make(TranslatesHtml::class), null === $fallback ? null : self::FALLBACKS[$fallback]);
            $complete = true;
            foreach ($targets as $target) {
                $document = $this->translate($pending, $html, $target);
                $complete = $document->isComplete() && $complete;
                $path = $this->outputPath($output, $input, $target, \count($targets) > 1);
                if (null === $path) {
                    // Raw and at every verbosity: the formatter would strip "<info>"-like tags and backslashes from the
                    // HTML, and -q would drop the result.
                    $this->output->write($document->html(), false, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
                } elseif (false === @file_put_contents($path, $document->html())) {
                    $errors->error(\sprintf('Cannot write "%s".', $path));

                    return self::FAILURE;
                }

                $this->summarize($target, $document, $path);
                if (!$document->isComplete()) {
                    $errors->warning(\sprintf('%s is incomplete: %d issues; run with -v for details.', $target, \count($document->issues())));
                }
            }
        } catch (TranslatorException $exception) {
            $errors->error($exception->getMessage());

            return self::FAILURE;
        }

        return $complete ? self::SUCCESS : self::FAILURE;
    }

    private function pending(TranslatesHtml $translator, ?FallbackStrategy $fallback): PendingTranslation
    {
        $pending = new PendingTranslation($translator);
        $provider = $this->stringOption('provider');
        $profile = $this->stringOption('profile');
        $source = $this->stringOption('from');

        $pending = null === $provider ? $pending : $pending->provider($provider);
        $pending = null === $profile ? $pending : $pending->profile($profile);
        $pending = null === $source ? $pending : $pending->from($source);

        return $fallback instanceof FallbackStrategy ? $pending->fallback($fallback) : $pending;
    }

    private function translate(PendingTranslation $pending, string $html, string $target): TranslatedDocument
    {
        $errors = $this->output->getErrorStyle();
        // A progress bar only on a terminal: elsewhere (pipes, CI logs) it cannot be cleared and garbles the summary.
        if (!$errors->isDecorated()) {
            return $pending->translate($html, $target);
        }

        $bar = $errors->createProgressBar();
        $bar->setFormat(' %current%/%max% batches [%bar%] ' . $target);

        try {
            return $pending
                ->onProgress(static function (Progress $progress) use ($bar): void {
                    $bar->setMaxSteps($progress->batchesTotal);
                    $bar->setProgress($progress->batchesDone);
                })
                ->translate($html, $target);
        } finally {
            $bar->clear();
        }
    }

    private function summarize(string $target, TranslatedDocument $document, ?string $path): void
    {
        $stats = $document->stats();
        $tokens = null === $stats->inputTokens && null === $stats->outputTokens
            ? ''
            : \sprintf(', %s → %s tokens', number_format($stats->inputTokens ?? 0), number_format($stats->outputTokens ?? 0));
        $errors = $this->output->getErrorStyle();

        $errors->writeln(\sprintf(
            '%s%s: %d units, %d batches, %d cache hits, %d retries%s, %d issues',
            $target,
            null === $path ? '' : ' → ' . $path,
            $stats->units,
            $stats->batches,
            $stats->cacheHits,
            $stats->retries,
            $tokens,
            \count($document->issues()),
        ));

        if ($errors->isVerbose()) {
            foreach ($document->issues() as $issue) {
                $errors->writeln(\sprintf('  %s %s: %s', $issue->type->name, $issue->unitId->toString(), $issue->message));
            }
        }
    }

    /**
     * Null: STDOUT.
     */
    private function outputPath(?string $output, string $input, string $target, bool $several): ?string
    {
        // The normalized tag, so --to=pt_br and --to=pt-BR name the same file.
        $target = Locale::fromString($target)->toString();
        if (null !== $output) {
            return str_replace('{locale}', $target, $output);
        }

        if (!$several) {
            return null;
        }

        $info = pathinfo($input);
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';

        return ($info['dirname'] ?? '.') . '/' . $info['filename'] . '.' . $target . $extension;
    }

    private function read(string $input): ?string
    {
        if ('-' !== $input) {
            $html = is_file($input) ? @file_get_contents($input) : false;

            return false === $html ? null : $html;
        }

        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $html = stream_get_contents($stream ?? \STDIN);

        return false === $html ? null : $html;
    }

    /**
     * @return list<string>
     */
    private function targets(): array
    {
        $targets = $this->option('to');

        return \is_array($targets) ? array_values(array_filter($targets, static fn(mixed $target): bool => \is_string($target) && '' !== $target)) : [];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
