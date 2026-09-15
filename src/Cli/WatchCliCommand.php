<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\RunnerOutput;
use Heyosseus\Sloppy\Runner\WatchOptions;
use Heyosseus\Sloppy\Runner\WatchRunner;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Watch\Dashboard;
use Heyosseus\Sloppy\Watch\EditorCommand;
use SplFileObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * `sloppy watch` -- the score, left open next to whatever is writing the code.
 */
#[AsCommand(name: 'watch', description: 'Watch the project and redraw the score as files change')]
final class WatchCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this->configureSharedOptions();

        $this
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many ranked findings to list')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Milliseconds between checks for changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = new WatchOptions(
            paths: $this->stringListOption($input, 'path'),
            rules: $this->stringListOption($input, 'rule'),
            minConfidence: $this->intOption($input, 'min-confidence'),
            top: $this->intOption($input, 'top'),
            interval: max(50, $this->intOption($input, 'interval') ?? 250),
        );

        $dashboard = $this->dashboard($output, $options);

        return $this->runWith($input, $output, fn (Sloppy $sloppy, RunnerOutput $runnerOutput): ExitCode => (new WatchRunner(
            $dashboard,
            EditorCommand::from(getenv()),
            new ShellEditorLauncher,
        ))->run($sloppy, $options, $runnerOutput));
    }

    /**
     * The real terminal, with every part of it named here rather than reached
     * for inside the dashboard -- which is what makes the dashboard testable
     * without one.
     */
    private function dashboard(OutputInterface $output, WatchOptions $options): Dashboard
    {
        return new TerminalDashboard(
            $output,
            new SplFileObject('php://stdin'),
            new SttyTerminalMode,
            (new Terminal)->getWidth(),
            $options->interval,
            // Redirected into a file or a pipe there is nothing to draw on and no
            // key to stop with, so the runner declines rather than looping.
            stream_isatty(STDOUT),
        );
    }
}
