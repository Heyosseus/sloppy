<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Output\OutputFormat;
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
            ->addOption('format', null, InputOption::VALUE_REQUIRED, OutputFormat::listing().'; sarif, gitlab and rector carry the new findings', 'console')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Lowest severity of NEW finding that fails the command, or "never"')
            ->addOption('merge-base', null, InputOption::VALUE_NONE, 'Compare against where HEAD forked from <base> (git merge-base) rather than its tip')
            ->addOption('coverage', null, InputOption::VALUE_REQUIRED, 'Path to a clover or cobertura report; findings in untested files rank higher')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Include each rule\'s "why this matters" text')
            ->addOption('explain-risk', null, InputOption::VALUE_NONE, 'Show the arithmetic behind each risk value (the console report becomes the risk-ordered review)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runDiff(
            $input,
            $output,
            explain: $input->getOption('explain') === true,
            explainRisk: $input->getOption('explain-risk') === true,
        );
    }
}
