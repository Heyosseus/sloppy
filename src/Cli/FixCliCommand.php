<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\FixOptions;
use Heyosseus\Sloppy\Runner\FixRunner;
use Heyosseus\Sloppy\Runner\RunnerOutput;
use Heyosseus\Sloppy\Sloppy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `sloppy fix` -- hand the fixable findings to Rector, then to Pint.
 */
#[AsCommand(name: 'fix', description: 'Fix what Rector can fix, format with Pint, and report what is left')]
final class FixCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this->configureSharedOptions();

        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing anything')
            ->addOption('no-rector', null, InputOption::VALUE_NONE, 'Skip Rector, only write the configuration')
            ->addOption('no-pint', null, InputOption::VALUE_NONE, 'Skip the formatting pass')
            ->addOption('keep-config', null, InputOption::VALUE_NONE, 'Leave the generated Rector configuration in place')
            ->addOption('rector-config', null, InputOption::VALUE_REQUIRED, 'Filename for the generated Rector configuration', 'rector-sloppy.php');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runWith($input, $output, fn (Sloppy $sloppy, RunnerOutput $runnerOutput): ExitCode => (new FixRunner)->run($sloppy, new FixOptions(
            paths: $this->stringListOption($input, 'path'),
            rules: $this->stringListOption($input, 'rule'),
            minConfidence: $this->intOption($input, 'min-confidence'),
            dryRun: $input->getOption('dry-run') === true,
            withRector: $input->getOption('no-rector') !== true,
            withPint: $input->getOption('no-pint') !== true,
            keepConfig: $input->getOption('keep-config') === true,
            configFile: $this->stringOption($input, 'rector-config') ?? 'rector-sloppy.php',
        ), $runnerOutput));
    }
}
