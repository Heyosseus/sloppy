<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\RulesOptions;
use Heyosseus\Sloppy\Runner\RulesRunner;
use Heyosseus\Sloppy\Runner\RunnerOutput;
use Heyosseus\Sloppy\Sloppy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `sloppy rules` -- teach the agents writing the code what this project flags.
 */
#[AsCommand(name: 'rules', description: 'Write this project\'s rules into CLAUDE.md, .cursorrules, AGENTS.md and friends')]
final class RulesCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this->configureSharedOptions();

        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'claude, cursor, agents, copilot, windsurf, markdown or json (repeatable)')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write to this file instead of the format\'s usual one')
            ->addOption('stdout', null, InputOption::VALUE_NONE, 'Print the ruleset instead of writing a file')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite a generated file that already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runWith($input, $output, fn (Sloppy $sloppy, RunnerOutput $runnerOutput): ExitCode => (new RulesRunner)->run($sloppy, new RulesOptions(
            formats: RulesOptions::parseFormats($this->stringListOption($input, 'format')),
            output: $this->stringOption($input, 'output'),
            stdout: $input->getOption('stdout') === true,
            force: $input->getOption('force') === true,
        ), $runnerOutput));
    }
}
