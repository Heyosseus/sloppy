<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\HealthOptions;
use Heyosseus\Sloppy\Runner\HealthRunner;
use Heyosseus\Sloppy\Runner\RunnerOutput;
use Heyosseus\Sloppy\Sloppy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `sloppy health` -- the snapshot every dashboard-shaped surface reads.
 */
#[AsCommand(name: 'health', description: 'Report the project\'s score and what is dragging it down')]
final class HealthCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this->configureSharedOptions();

        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the snapshot as JSON')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Re-analyse instead of reading the cached snapshot')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many ranked findings to include');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runWith($input, $output, fn (Sloppy $sloppy, RunnerOutput $runnerOutput): ExitCode => (new HealthRunner)->run($sloppy, new HealthOptions(
            paths: $this->stringListOption($input, 'path'),
            rules: $this->stringListOption($input, 'rule'),
            minConfidence: $this->intOption($input, 'min-confidence'),
            json: $input->getOption('json') === true,
            fresh: $input->getOption('fresh') === true,
            top: $this->intOption($input, 'top'),
        ), $runnerOutput));
    }
}
