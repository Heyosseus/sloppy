<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

use Closure;

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
 *
 * It also says when the next stat of the tree is worth making. Walking a large
 * project four times a second while nobody is typing is a fan running for
 * nothing, so polls back off while the tree holds still -- first not at all,
 * so a pause between two saves still feels instant, then doubling up to a
 * ceiling -- and while it is quiet, never come closer together than ten
 * walks' worth of time, however long one walk takes. The first change seen
 * puts it straight back to every tick until the change has settled.
 */
final class FileWatcher
{
    /** Idle polls at full speed before backing off at all. */
    private const int EAGER_POLLS = 4;

    /** The first backed-off delay, doubled for each idle poll after it. */
    private const float FIRST_DELAY = 0.25;

    /** A poll may cost at most this share of the time between polls. */
    private const int COST_FACTOR = 10;

    private ?TreeState $previous = null;

    /** @var array<string, true> */
    private array $pending = [];

    private int $idle = 0;

    private float $next = 0.0;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param  float  $ceiling  The longest an idle tree goes unchecked, in seconds.
     * @param  (callable(): float)|null  $clock  Seconds, for a test to move time along.
     */
    public function __construct(private readonly float $ceiling = 2.0, ?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn (): float => microtime(true) : $clock(...);
    }

    /**
     * Whether the tree should be walked and polled on this tick.
     */
    public function due(): bool
    {
        return ($this->clock)() >= $this->next;
    }

    /**
     * How long the watcher will wait from now before the next poll is due.
     */
    public function delay(): float
    {
        return max(0.0, $this->next - ($this->clock)());
    }

    /**
     * Relative paths that have finished changing, or an empty list when there
     * is nothing to analyse yet.
     *
     * @return list<string>
     */
    public function poll(TreeState $state, float $cost = 0.0): array
    {
        $changes = $this->changes($state);
        $this->schedule($changes !== [] || $this->pending !== [], $cost);

        return $changes;
    }

    /**
     * @return list<string>
     */
    private function changes(TreeState $state): array
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

    /**
     * @param  bool  $busy  Whether something changed or is still settling.
     * @param  float  $cost  How long this poll's walk of the tree took, in seconds.
     */
    private function schedule(bool $busy, float $cost): void
    {
        $this->idle = $busy ? 0 : $this->idle + 1;

        $backoff = $this->idle <= self::EAGER_POLLS
            ? 0.0
            : min($this->ceiling, self::FIRST_DELAY * 2 ** min(16, $this->idle - self::EAGER_POLLS - 1));

        // A quiet tree is not walked more often than its size allows. A busy
        // one is: a change has to settle and be analysed while the person who
        // made it is still looking.
        $this->next = ($this->clock)() + ($busy ? 0.0 : max($backoff, min($this->ceiling, $cost * self::COST_FACTOR)));
    }
}
