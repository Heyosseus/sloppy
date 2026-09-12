<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\DiffOptions;
use Heyosseus\Sloppy\Runner\DiffRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'diff', description: 'Report the findings a change introduced, relative to a git revision')]
final class DiffCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this->configureSharedOptions();

        $this
            ->addArgument('base', InputArgument::OPTIONAL, 'Revision to compare the working tree against, e.g. HEAD~1 or main', 'HEAD')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'console or json', 'console')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Lowest severity of NEW finding that fails the command, or "never"')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Include each rule\'s "why this matters" text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runnerOutput = $this->runnerOutput($input, $output);

        try {
            $sloppy = $this->sloppy($input);
            $base = $input->getArgument('base');

            $options = new DiffOptions(
                base: is_string($base) && trim($base) !== '' ? trim($base) : 'HEAD',
                paths: $this->stringListOption($input, 'path'),
                format: OutputFormat::parse($this->stringOption($input, 'format') ?? 'console'),
                failOn: $this->stringOption($input, 'fail-on'),
                minConfidence: $this->intOption($input, 'min-confidence'),
                rules: $this->stringListOption($input, 'rule'),
                explain: $input->getOption('explain') === true,
            );
        } catch (Throwable $exception) {
            $runnerOutput->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        return (new DiffRunner)->run($sloppy, $options, $runnerOutput)->value;
    }
}
