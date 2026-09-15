<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Integrations\HealthSnapshot;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Watch\Dashboard;
use Heyosseus\Sloppy\Watch\EditorCommand;
use Heyosseus\Sloppy\Watch\EditorLauncher;
use Heyosseus\Sloppy\Watch\FileWatcher;
use Heyosseus\Sloppy\Watch\FrameRenderer;
use Heyosseus\Sloppy\Watch\KeyPress;
use Heyosseus\Sloppy\Watch\ParsedFileCache;
use Heyosseus\Sloppy\Watch\TreeState;
use Heyosseus\Sloppy\Watch\WatchState;
use Throwable;

/**
 * The score, on screen, while the code is still being written.
 *
 * Every other command answers once and exits, which means somebody has to
 * decide to ask. The most useful moment to know what a change did is while an
 * agent is still making it, and that is the moment nobody stops to run a
 * command. This is the same analysis, left open.
 *
 * It redraws by re-parsing only the file that moved and then running every
 * rule over the whole project, so a tick is not an approximation of
 * `sloppy scan` -- it is the same answer, arrived at without re-reading the
 * files nobody touched.
 */
final readonly class WatchRunner
{
    public function __construct(
        private Dashboard $dashboard,
        private EditorCommand $editor,
        private EditorLauncher $launcher,
    ) {}

    public function run(Sloppy $sloppy, WatchOptions $options, RunnerOutput $output): ExitCode
    {
        try {
            $sloppy = (new ConfigurationResolver)->resolve($sloppy, $options);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        if (! $sloppy->configuration->enabled()) {
            $output->info('Sloppy is disabled (sloppy.enabled is false).');

            return ExitCode::Success;
        }

        if (! $this->dashboard->isTerminal()) {
            // Nothing would be watching the frame and no key could stop it, so
            // this would be an infinite loop with its output going to a file.
            $output->error('sloppy watch needs a terminal. Use `sloppy health` for a one-off report, or `sloppy health --json` to pipe one somewhere.');

            return ExitCode::Error;
        }

        $this->dashboard->open();

        try {
            $this->loop($sloppy, $options);
        } finally {
            // Whatever happened, the terminal goes back the way it was found.
            $this->dashboard->close();
        }

        return ExitCode::Success;
    }

    private function loop(Sloppy $sloppy, WatchOptions $options): void
    {
        $cache = new ParsedFileCache;
        $watcher = new FileWatcher;
        $renderer = new FrameRenderer($this->dashboard->width(), $this->dashboard->readsKeypresses());

        // Bank what is on disk before the first frame, so an edit made while
        // that frame was being drawn is still seen as a change.
        $files = $sloppy->fileMap();
        $tree = TreeState::of($files);
        $watcher->poll($tree);

        $state = new WatchState($this->analyse($sloppy, $cache, $options, $files, $tree));
        $this->dashboard->draw($renderer->render($state));

        while (true) {
            $key = $this->dashboard->read()->press;

            if ($key === KeyPress::Quit) {
                return;
            }

            $state = $this->pressed($key, $state, $sloppy, $cache, $options);
            $state = $this->changes($state, $sloppy, $cache, $watcher, $options);

            $this->dashboard->draw($renderer->render($state));
        }
    }

    /**
     * What a keypress does to the dashboard.
     */
    private function pressed(
        KeyPress $key,
        WatchState $state,
        Sloppy $sloppy,
        ParsedFileCache $cache,
        WatchOptions $options,
    ): WatchState {
        return match ($key) {
            KeyPress::Up => $state->moveUp(),
            KeyPress::Down => $state->moveDown(),
            KeyPress::Open => $this->open($state, $sloppy),
            KeyPress::Rescan => $this->rescan($state, $sloppy, $cache, $options),
            default => $state,
        };
    }

    /**
     * Analyse again when the watched tree has settled, leaving the frame alone
     * when it has not.
     */
    private function changes(
        WatchState $state,
        Sloppy $sloppy,
        ParsedFileCache $cache,
        FileWatcher $watcher,
        WatchOptions $options,
    ): WatchState {
        // The one walk of the tree this tick gets: polling and analysing both
        // read it, and reading it twice per tick would double the cost of the
        // thing the loop does most often.
        $files = $sloppy->fileMap();
        $tree = TreeState::of($files);

        $changed = $watcher->poll($tree);

        if ($changed === []) {
            return $state;
        }

        $started = microtime(true);

        return $state->withSnapshot(
            $this->analyse($sloppy, $cache, $options, $files, $tree),
            $changed,
            microtime(true) - $started,
        );
    }

    /**
     * Throw away every cached parse and read the tree again.
     *
     * This is the answer to the one edit polling cannot see: same length,
     * same second. It is rare, and cheaper to offer a key for than to make
     * every tick hash every file against.
     */
    private function rescan(WatchState $state, Sloppy $sloppy, ParsedFileCache $cache, WatchOptions $options): WatchState
    {
        $cache->forget();

        $files = $sloppy->fileMap();
        $started = microtime(true);

        return $state->withSnapshot(
            $this->analyse($sloppy, $cache, $options, $files, TreeState::of($files)),
            [],
            microtime(true) - $started,
        );
    }

    /**
     * Hand the selected finding to the editor, giving it the terminal for as
     * long as it wants it.
     */
    private function open(WatchState $state, Sloppy $sloppy): WatchState
    {
        $finding = $state->selectedFinding();

        if ($finding === null) {
            return $state;
        }

        $command = $this->editor->for($sloppy->configuration->absolutePath($finding['file']), $finding['line']);

        if ($command === null) {
            return $state;
        }

        $this->dashboard->close();
        $this->launcher->launch($command);
        $this->dashboard->open();

        return $state;
    }

    /**
     * One tick's analysis: re-parse what moved, then run every rule over
     * everything.
     *
     * @param  array<string, string>  $files  Relative path => absolute path, as the caller already read it.
     */
    private function analyse(
        Sloppy $sloppy,
        ParsedFileCache $cache,
        WatchOptions $options,
        array $files,
        TreeState $tree,
    ): HealthSnapshot {
        $project = $cache->sync($tree, $files);

        return HealthSnapshot::from(
            $sloppy->analyzer()->analyzeParsed($project->files, $project->errors),
            $sloppy->risks(),
            $options->top ?? $sloppy->configuration->health()->top,
        );
    }
}
