<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

use Heyosseus\Sloppy\Integrations\HealthSnapshot;

/**
 * Everything the dashboard draws, in one value.
 *
 * The frame is a function of this and nothing else -- no clock, no terminal,
 * no filesystem -- which is what lets the whole visual be tested as a string
 * comparison. The loop's job is to produce the next one of these; the
 * renderer's job is to turn one into lines.
 */
final readonly class WatchState
{
    /**
     * @param  int  $selected  Index into the snapshot's ranked findings.
     * @param  list<string>  $changed  Relative paths that triggered this tick.
     * @param  float  $duration  Seconds the last analysis took.
     */
    public function __construct(
        public HealthSnapshot $snapshot,
        public int $selected = 0,
        public array $changed = [],
        public float $duration = 0.0,
    ) {}

    public function moveUp(): self
    {
        return $this->withSelection($this->selected - 1);
    }

    public function moveDown(): self
    {
        return $this->withSelection($this->selected + 1);
    }

    /**
     * @return array{rule: string, name: string, severity: string, file: string, line: int, message: string, risk: float}|null
     */
    public function selectedFinding(): ?array
    {
        return $this->snapshot->top[$this->selected] ?? null;
    }

    /**
     * The result of a tick that worked.
     *
     * The selection survives it where it can: fixing something further down
     * the list should not throw the reader back to the top. When the list got
     * shorter than the cursor, the cursor comes back to the last row rather
     * than pointing past the end.
     *
     * @param  list<string>  $changed
     */
    public function withSnapshot(HealthSnapshot $snapshot, array $changed, float $duration): self
    {
        return (new self($snapshot, $this->selected, $changed, $duration))->withSelection($this->selected);
    }

    private function withSelection(int $index): self
    {
        $last = count($this->snapshot->top) - 1;

        return new self(
            $this->snapshot,
            max(0, min($index, $last)),
            $this->changed,
            $this->duration,
        );
    }
}
