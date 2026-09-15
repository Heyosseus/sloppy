<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

/**
 * Turns a stream of {@see TreeState}s into "these files are done changing".
 *
 * Analysis is the expensive part of a tick, so a watch loop must not start one
 * per write. An editor saving a file in two syscalls, a formatter rewriting it
 * a moment later, or a coding agent producing twenty files in a burst all
 * arrive as several polls in a row with something different each time. This
 * collects them and reports once the tree has held still for a full poll.
 *
 * It does no I/O: the caller stats the tree and hands the result in, which is
 * what lets the settling behaviour be tested without writing files and waiting
 * on the clock.
 */
final class FileWatcher
{
    private ?TreeState $previous = null;

    /** @var array<string, true> */
    private array $pending = [];

    /**
     * Relative paths that have finished changing, or an empty list when there
     * is nothing to analyse yet.
     *
     * @return list<string>
     */
    public function poll(TreeState $state): array
    {
        $previous = $this->previous;
        $this->previous = $state;

        // The first poll only learns what is there. The runner has already
        // analysed the tree once by the time it happens, so reporting every
        // file as changed would only buy a second identical analysis.
        if (! $previous instanceof TreeState) {
            return [];
        }

        $changed = $state->changesSince($previous);

        if ($changed !== []) {
            foreach ($changed as $path) {
                $this->pending[$path] = true;
            }

            return [];
        }

        $settled = array_keys($this->pending);
        $this->pending = [];

        sort($settled);

        return $settled;
    }
}
