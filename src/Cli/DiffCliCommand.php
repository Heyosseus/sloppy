<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
            ->addOption('coverage', null, InputOption::VALUE_REQUIRED, 'Path to a clover or cobertura report; findings in untested files rank higher')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Include each rule\'s "why this matters" text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runDiff($input, $output, explain: $input->getOption('explain') === true);
    }
}
