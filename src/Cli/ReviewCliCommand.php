<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `diff` answers "did this change make it worse?". `review` answers the
 * question a reviewer actually has next: "what should I read first?".
 *
 * Same analysis, same score, same exit code -- only the presentation differs,
 * so adopting the reading order costs nothing and changes no build outcome.
 */
#[AsCommand(name: 'review', description: 'Rank what a change introduced by risk, in the order worth reading')]
final class ReviewCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this->configureSharedOptions();

        $this
            ->addArgument('base', InputArgument::OPTIONAL, 'Revision to compare the working tree against, e.g. HEAD~1 or main', 'HEAD')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'console, json or markdown', 'console')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Lowest severity of NEW finding that fails the command, or "never"')
            ->addOption('coverage', null, InputOption::VALUE_REQUIRED, 'Path to a clover or cobertura report; findings in untested files rank higher')
            ->addOption('explain-risk', null, InputOption::VALUE_NONE, 'Show the arithmetic behind each risk value');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runDiff($input, $output, explainRisk: $input->getOption('explain-risk') === true, review: true);
    }
}
