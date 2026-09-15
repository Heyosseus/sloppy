<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Integrations\HealthSnapshot;
use Heyosseus\Sloppy\Scoring\ScoreBand;
use Heyosseus\Sloppy\Watch\WatchState;

/**
 * A snapshot carrying a given number of ranked findings.
 */
function snapshotOf(int $topCount, int $score = 72): HealthSnapshot
{
    $top = [];

    for ($index = 0; $index < $topCount; $index++) {
        $top[] = [
            'rule' => 'SL10'.$index,
            'name' => 'Rule '.$index,
            'severity' => 'high',
            'file' => 'app/File'.$index.'.php',
            'line' => 10 + $index,
            'message' => 'A message.',
            'risk' => 5.0,
        ];
    }

    return new HealthSnapshot(
        score: $score,
        band: ScoreBand::Clean,
        findings: $topCount,
        files: 3,
        lines: 100,
        bySeverity: [],
        byCategory: [],
        top: $top,
        skippedRules: [],
        generatedAt: 0,
    );
}

it('starts on the first finding', function (): void {
    expect((new WatchState(snapshotOf(3)))->selected)->toBe(0);
});

it('moves down the list and back up again', function (): void {
    $state = new WatchState(snapshotOf(3));

    expect($state->moveDown()->selected)->toBe(1)
        ->and($state->moveDown()->moveDown()->selected)->toBe(2)
        ->and($state->moveDown()->moveUp()->selected)->toBe(0);
});

it('stops at the ends rather than wrapping', function (): void {
    // Wrapping in a three-line list is disorienting: the eye loses its place.
    $state = new WatchState(snapshotOf(2));

    expect($state->moveUp()->selected)->toBe(0)
        ->and($state->moveDown()->moveDown()->moveDown()->selected)->toBe(1);
});

it('stays put when there is nothing to select', function (): void {
    $state = new WatchState(snapshotOf(0));

    expect($state->moveDown()->selected)->toBe(0)
        ->and($state->moveUp()->selected)->toBe(0)
        ->and($state->selectedFinding())->toBeNull();
});

it('hands back the finding it is sitting on', function (): void {
    $finding = (new WatchState(snapshotOf(3)))->moveDown()->selectedFinding();

    expect($finding)->not->toBeNull()
        ->and($finding['file'])->toBe('app/File1.php')
        ->and($finding['line'])->toBe(11);
});

it('carries what changed and how long the tick took', function (): void {
    $state = (new WatchState(snapshotOf(1)))->withSnapshot(snapshotOf(2, 64), ['app/A.php'], 1.25);

    expect($state->snapshot->score)->toBe(64)
        ->and($state->changed)->toBe(['app/A.php'])
        ->and($state->duration)->toBe(1.25);
});

it('keeps the selection when a new snapshot still has that finding', function (): void {
    $state = (new WatchState(snapshotOf(4)))->moveDown()->moveDown();

    expect($state->withSnapshot(snapshotOf(4), [], 0.1)->selected)->toBe(2);
});

it('pulls the selection back when the list got shorter', function (): void {
    // Fixing the top finding must not leave the cursor pointing past the end.
    $state = (new WatchState(snapshotOf(4)))->moveDown()->moveDown()->moveDown();

    expect($state->withSnapshot(snapshotOf(2), [], 0.1)->selected)->toBe(1)
        ->and($state->withSnapshot(snapshotOf(0), [], 0.1)->selected)->toBe(0);
});
