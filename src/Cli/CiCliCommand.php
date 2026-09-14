<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\CiOptions;
use Heyosseus\Sloppy\Runner\CiRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\RunnerOutput;
use Heyosseus\Sloppy\Sloppy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `sloppy ci` -- the whole of a pipeline step.
 *
 * Everything it needs beyond "run" is already in the environment: which branch
 * the pull request targets, which provider is watching, where the job summary
 * goes. Reading those is the command's entire job, and it exists so that no
 * team has to write the same four decisions into their YAML and get one of
 * them subtly wrong.
 */
#[AsCommand(name: 'ci', description: 'Analyse a change the way the surrounding CI system wants it reported')]
final class CiCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this->configureSharedOptions();

        $this
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Revision to compare against; read from the CI environment when omitted')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'github, gitlab, json, markdown, sarif or console; the provider decides when omitted')
            ->addOption('report', null, InputOption::VALUE_REQUIRED, 'Write the machine-readable report to this file instead of standard output')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Lowest severity that fails the step, or "never"')
            ->addOption('scan', null, InputOption::VALUE_NONE, 'Analyse the whole project instead of comparing against a revision')
            ->addOption('no-summary', null, InputOption::VALUE_NONE, 'Do not write the job summary')
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Report every finding, including baselined ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runWith($input, $output, function (Sloppy $sloppy, RunnerOutput $runnerOutput) use ($input): ExitCode {
            $format = $this->stringOption($input, 'format');

            $options = new CiOptions(
                base: $this->stringOption($input, 'base'),
                paths: $this->stringListOption($input, 'path'),
                rules: $this->stringListOption($input, 'rule'),
                failOn: $this->stringOption($input, 'fail-on'),
                minConfidence: $this->intOption($input, 'min-confidence'),
                format: $format === null ? null : OutputFormat::parse($format),
                report: $this->stringOption($input, 'report'),
                summary: $input->getOption('no-summary') !== true,
                scan: $input->getOption('scan') === true,
                noBaseline: $input->getOption('no-baseline') === true,
            );

            return (new CiRunner(CiEnvironment::fromGlobals()))->run($sloppy, $options, $runnerOutput);
        });
    }
}
