<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Runner\AgentsOptions;
use Heyosseus\Sloppy\Runner\AgentsRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\RunnerOutput;
use Heyosseus\Sloppy\Sloppy;
use InvalidArgumentException;
use Phar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `sloppy agents install` -- make the agent check its own work, every time.
 */
#[AsCommand(name: 'agents', description: 'Install hooks so Claude Code runs Sloppy after every edit and before it finishes')]
final class AgentsCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'install', 'install')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project directory (default: the nearest one above the working directory)')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a sloppy configuration file')
            ->addOption('local', null, InputOption::VALUE_NONE, 'Write .claude/settings.local.json, which stays out of git, instead of the shared settings')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the settings that would be written, and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runWith($input, $output, function (Sloppy $sloppy, RunnerOutput $runnerOutput) use ($input): ExitCode {
            $action = (string) $input->getArgument('action');

            if ($action !== 'install') {
                throw new InvalidArgumentException(sprintf('Unknown action [%s]. The only action is install.', $action));
            }

            return (new AgentsRunner)->run($sloppy, new AgentsOptions(
                local: $input->getOption('local') === true,
                dryRun: $input->getOption('dry-run') === true,
                binary: $this->runningBinary(),
            ), $runnerOutput);
        });
    }

    /**
     * The binary running this command, for a project with no copy of its own:
     * the phar itself when there is one, since its script path is only the
     * stub inside it.
     */
    private function runningBinary(): string
    {
        $phar = Phar::running(false);

        if ($phar !== '') {
            return $phar;
        }

        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $resolved = is_string($script) && $script !== '' ? realpath($script) : false;

        return $resolved === false ? 'vendor/bin/sloppy' : $resolved;
    }
}
