<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\ScanOptions;
use Heyosseus\Sloppy\Runner\ScanRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'scan', description: 'Analyse a project for AI-slop code patterns')]
final class ScanCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this->configureSharedOptions();

        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'console or json', 'console')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Lowest severity that fails the command, or "never"')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Include each rule\'s "why this matters" text')
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Report every finding, including baselined ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runnerOutput = $this->runnerOutput($input, $output);

        try {
            $sloppy = $this->sloppy($input);
            $options = new ScanOptions(
                paths: $this->stringListOption($input, 'path'),
                format: OutputFormat::parse($this->stringOption($input, 'format') ?? 'console'),
                failOn: $this->stringOption($input, 'fail-on'),
                minConfidence: $this->intOption($input, 'min-confidence'),
                rules: $this->stringListOption($input, 'rule'),
                explain: $input->getOption('explain') === true,
                noBaseline: $input->getOption('no-baseline') === true,
            );
        } catch (Throwable $exception) {
            $runnerOutput->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        return (new ScanRunner)->run($sloppy, $options, $runnerOutput)->value;
    }
}
