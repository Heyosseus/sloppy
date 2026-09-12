<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Configuration\ConfigurationLoader;
use Heyosseus\Sloppy\Sloppy;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The options every standalone command shares, and the bootstrapping Laravel
 * would otherwise have done.
 */
abstract class CliCommandBase extends Command
{
    protected function configureSharedOptions(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project directory to analyse (default: the nearest one above the working directory)')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a sloppy configuration file')
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Analyse these paths instead of the configured ones')
            ->addOption('min-confidence', null, InputOption::VALUE_REQUIRED, 'Drop findings below this confidence (0-100)')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only these rule IDs, e.g. --rule=SL101');
    }

    protected function sloppy(InputInterface $input): Sloppy
    {
        $root = (new ProjectLocator)->locate($this->stringOption($input, 'project'), (string) getcwd());
        $loader = new ConfigurationLoader($root);

        return new Sloppy($loader->load($this->stringOption($input, 'config')));
    }

    protected function runnerOutput(InputInterface $input, OutputInterface $output): SymfonyRunnerOutput
    {
        return new SymfonyRunnerOutput(new SymfonyStyle($input, $output));
    }

    protected function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function intOption(InputInterface $input, string $name): ?int
    {
        $value = $this->stringOption($input, $name);

        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('--%s must be a number.', $name));
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    protected function stringListOption(InputInterface $input, string $name): array
    {
        $value = $input->getOption($name);

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        /** @var mixed $item */
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }
}
