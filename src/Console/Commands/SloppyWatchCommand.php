<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Cli\ShellEditorLauncher;
use Heyosseus\Sloppy\Cli\SttyTerminalMode;
use Heyosseus\Sloppy\Cli\TerminalDashboard;
use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\WatchOptions;
use Heyosseus\Sloppy\Runner\WatchRunner;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Watch\EditorCommand;
use SplFileObject;
use Symfony\Component\Console\Terminal;

/**
 * `php artisan sloppy:watch` -- the same dashboard as `sloppy watch`, for a
 * project that already has Artisan.
 */
final class SloppyWatchCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy:watch
        {--path=* : Watch these paths instead of the configured ones}
        {--rule=* : Report only these rule IDs}
        {--min-confidence= : Drop findings below this confidence (0-100)}
        {--top= : How many ranked findings to list}
        {--interval= : Milliseconds between checks for changes}';

    protected $description = 'Watch the project and redraw the score as files change';

    public function handle(Sloppy $sloppy): int
    {
        $options = new WatchOptions(
            paths: $this->stringListOption('path'),
            rules: $this->stringListOption('rule'),
            minConfidence: $this->intOption('min-confidence'),
            top: $this->intOption('top'),
            interval: max(50, $this->intOption('interval') ?? 250),
        );

        $dashboard = new TerminalDashboard(
            $this->getOutput(),
            new SplFileObject('php://stdin'),
            new SttyTerminalMode,
            (new Terminal)->getWidth(),
            $options->interval,
            // Redirected into a file or a pipe there is nothing to draw on and no
            // key to stop with, so the runner declines rather than looping.
            stream_isatty(STDOUT),
        );

        return $this->runWith(fn (LaravelRunnerOutput $output): ExitCode => (new WatchRunner(
            $dashboard,
            EditorCommand::from(getenv()),
            new ShellEditorLauncher,
        ))->run($sloppy, $options, $output));
    }
}
