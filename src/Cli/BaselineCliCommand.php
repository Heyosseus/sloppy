<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Runner\BaselineOptions;
use Heyosseus\Sloppy\Runner\BaselineRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\RunnerOutput;
use Heyosseus\Sloppy\Sloppy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'baseline', description: 'Record the current findings as accepted, so only new ones fail')]
final class BaselineCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this->configureSharedOptions();

        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing baseline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runWith($input, $output, fn (Sloppy $sloppy, RunnerOutput $runnerOutput): ExitCode => (new BaselineRunner)->run($sloppy, new BaselineOptions(
            paths: $this->stringListOption($input, 'path'),
            minConfidence: $this->intOption($input, 'min-confidence'),
            rules: $this->stringListOption($input, 'rule'),
            force: $input->getOption('force') === true,
        ), $runnerOutput));
    }
}
