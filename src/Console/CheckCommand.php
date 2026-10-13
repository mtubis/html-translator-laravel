<?php

declare(strict_types=1);

namespace HtmlTranslator\Laravel\Console;

use HtmlTranslator\Exception\TranslatorException;
use HtmlTranslator\Laravel\HtmlTranslationManager;
use HtmlTranslator\Laravel\PendingTranslation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Validates the whole configuration in one run (ADR-0007): the settings, every provider entry and every profile, so all
 * problems are listed at once. No network unless --ping. Messages never contain secrets: they come from the package's
 * and the core's validation, which only name keys and types.
 */
#[AsCommand(name: 'html-translator:check', description: 'Check the configuration of every provider and profile')]
final class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'html-translator:check
        {--provider=* : Check only these provider entries}
        {--ping : Also translate one short paragraph with each provider (network, costs a few tokens)}';

    private const string PING_HTML = '<p>Hello</p>';

    public function handle(Container $container): int
    {
        try {
            $manager = $container->make(HtmlTranslationManager::class);
        } catch (TranslatorException $exception) {
            $this->reportFailure('settings', $exception);

            return self::FAILURE;
        }

        $failures = $this->check('default', $manager->defaultProvider(...)) ? 0 : 1;
        $working = [];
        foreach ($this->providers($manager) as $name) {
            if ($this->check('providers.' . $name, static fn(): mixed => $manager->translator($name))) {
                $working[] = $name;
            } else {
                ++$failures;
            }
        }

        foreach ($manager->profileNames() as $name) {
            $failures += $this->check('profiles.' . $name, static fn(): mixed => $manager->namedProfile($name)) ? 0 : 1;
        }

        $failures += $this->check('options', static fn(): mixed => $manager->options(new PendingTranslation($manager))) ? 0 : 1;

        if (true === $this->option('ping')) {
            foreach ($working as $name) {
                $failures += $this->ping($manager, $name) ? 0 : 1;
            }
        }

        $this->newLine();
        if (0 === $failures) {
            $this->components->info('The configuration is valid.');

            return self::SUCCESS;
        }

        $this->components->error(\sprintf('%d %s found.', $failures, 1 === $failures ? 'problem' : 'problems'));

        return self::FAILURE;
    }

    /**
     * @param callable(): mixed $check
     */
    private function check(string $label, callable $check): bool
    {
        try {
            $check();
        } catch (TranslatorException $exception) {
            $this->reportFailure($label, $exception);

            return false;
        }

        $this->components->twoColumnDetail($label, '<fg=green;options=bold>OK</>');

        return true;
    }

    /**
     * A real call: besides the core's errors, anything the HTTP stack throws is reported without stopping the run.
     */
    private function ping(HtmlTranslationManager $manager, string $name): bool
    {
        try {
            return $this->check('ping ' . $name, static fn(): mixed => $manager->provider($name)->translate(self::PING_HTML, 'de'));
        } catch (Throwable $exception) {
            // Only the class: messages of errors the core did not wrap may contain request details.
            $this->components->twoColumnDetail('ping ' . $name, '<fg=red;options=bold>FAIL</>');
            $this->line('  Unexpected ' . $exception::class . '; run the translation directly to see the details.');

            return false;
        }
    }

    private function reportFailure(string $label, TranslatorException $exception): void
    {
        $this->components->twoColumnDetail($label, '<fg=red;options=bold>FAIL</>');
        $this->line('  ' . $exception->getMessage());
    }

    /**
     * @return list<string>
     */
    private function providers(HtmlTranslationManager $manager): array
    {
        $selected = $this->option('provider');
        $selected = \is_array($selected) ? array_values(array_filter($selected, \is_string(...))) : [];

        return [] === $selected ? $manager->providerNames() : $selected;
    }
}
