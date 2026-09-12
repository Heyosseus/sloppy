<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Runner\BaselineOptions;
use Heyosseus\Sloppy\Runner\BaselineRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

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
        $runnerOutput = $this->runnerOutput($input, $output);

        try {
            $sloppy = $this->sloppy($input);

            $options = new BaselineOptions(
                paths: $this->stringListOption($input, 'path'),
                minConfidence: $this->intOption($input, 'min-confidence'),
                rules: $this->stringListOption($input, 'rule'),
                force: $input->getOption('force') === true,
            );
        } catch (Throwable $exception) {
            $runnerOutput->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        return (new BaselineRunner)->run($sloppy, $options, $runnerOutput)->value;
    }
}
