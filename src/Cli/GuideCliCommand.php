<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Runner\HelpRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `sloppy guide` -- what the commands are and when to reach for each.
 *
 * Named `guide` rather than `help` because Symfony already defines `help`,
 * and `sloppy help ci` printing one command's options is worth more than the
 * name is: this command answers the question that comes before that one,
 * which is which command to ask about.
 *
 * It is also the only command here that does not extend
 * {@see CliCommandBase}. Locating a project and announcing where its
 * configuration came from is the right opening for every command that
 * analyses something, and the wrong one for the command a person runs while
 * deciding whether to set a project up at all.
 */
#[AsCommand(name: 'guide', description: 'Explain what each Sloppy command is for')]
final class GuideCliCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (new HelpRunner)->run(new SymfonyRunnerOutput(new SymfonyStyle($input, $output)))->value;
    }
}
