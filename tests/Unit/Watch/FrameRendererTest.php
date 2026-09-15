<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Integrations\HealthSnapshot;
use Heyosseus\Sloppy\Scoring\ScoreBand;
use Heyosseus\Sloppy\Watch\FrameRenderer;
use Heyosseus\Sloppy\Watch\WatchState;

/**
 * @param  array<string, int>  $byCategory
 * @param  list<array{rule: string, name: string, severity: string, file: string, line: int, message: string, risk: float}>  $top
 */
function snapshot(
    int $score = 72,
    ScoreBand $band = ScoreBand::NeedsAttention,
    int $findings = 27,
    int $files = 61,
    array $byCategory = ['complexity' => 14, 'laravel' => 9],
    array $top = [],
): HealthSnapshot {
    return new HealthSnapshot(
        score: $score,
        band: $band,
        findings: $findings,
        files: $files,
        lines: 4200,
        bySeverity: [],
        byCategory: $byCategory,
        top: $top,
        skippedRules: [],
        generatedAt: 0,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{rule: string, name: string, severity: string, file: string, line: int, message: string, risk: float}
 */
function topRow(array $overrides = []): array
{
    /** @var array{rule: string, name: string, severity: string, file: string, line: int, message: string, risk: float} $row */
    $row = [...[
        'rule' => 'SL101',
        'name' => 'God Method',
        'severity' => 'high',
        'file' => 'app/Services/Report.php',
        'line' => 40,
        'message' => 'A message.',
        'risk' => 8.4,
    ], ...$overrides];

    return $row;
}

function frame(WatchState $state, int $width = 80, bool $keypresses = true): string
{
    return implode("\n", (new FrameRenderer($width, $keypresses))->render($state));
}

it('leads with the score and the band', function (): void {
    $output = frame(new WatchState(snapshot()));

    expect($output)->toContain('72/100')
        ->and($output)->toContain('Needs attention')
        ->and($output)->toContain('yellow');
});

it('draws the score as a bar of twenty cells', function (): void {
    $output = frame(new WatchState(snapshot(score: 75)));

    expect($output)->toContain(str_repeat('█', 15).'</>')
        ->and($output)->toContain(str_repeat('░', 5));
});

it('draws an empty bar at zero and a full one at a hundred', function (): void {
    expect(frame(new WatchState(snapshot(score: 0))))->toContain(str_repeat('░', 20))
        ->and(frame(new WatchState(snapshot(score: 100))))->toContain(str_repeat('█', 20));
});

it('lists each category with its count', function (): void {
    $output = frame(new WatchState(snapshot(byCategory: ['complexity' => 14, 'laravel' => 9])));

    expect($output)->toMatch('/complexity\s+14/')
        ->and($output)->toMatch('/laravel\s+9/');
});

it('scales the category bars against the biggest one', function (): void {
    $output = frame(new WatchState(snapshot(byCategory: ['complexity' => 12, 'laravel' => 6])));

    $lines = explode("\n", $output);
    $complexity = array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'complexity')))[0];
    $laravel = array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'laravel')))[0];

    expect(substr_count($complexity, '█'))->toBe(12)
        ->and(substr_count($laravel, '█'))->toBe(6);
});

it('numbers the findings, so they can be opened by number', function (): void {
    $output = frame(new WatchState(snapshot(top: [
        topRow(),
        topRow(['rule' => 'SL107', 'name' => 'Swallowed Exception', 'file' => 'app/Order.php', 'line' => 112]),
    ])));

    expect($output)->toContain('1')
        ->and($output)->toContain('SL101')
        ->and($output)->toContain('God Method')
        ->and($output)->toContain('app/Services/Report.php:40')
        ->and($output)->toContain('SL107')
        ->and($output)->toContain('app/Order.php:112');
});

it('marks the selected finding and only that one', function (): void {
    $state = (new WatchState(snapshot(top: [topRow(), topRow(['file' => 'app/B.php'])])))->moveDown();

    $marked = array_values(array_filter(
        explode("\n", frame($state)),
        static fn (string $line): bool => str_contains($line, '›'),
    ));

    expect($marked)->toHaveCount(1)
        ->and($marked[0])->toContain('app/B.php');
});

it('says so when there is nothing to read', function (): void {
    $output = frame(new WatchState(snapshot(score: 100, band: ScoreBand::Clean, findings: 0, byCategory: [], top: [])));

    expect($output)->toContain('Nothing flagged')
        ->and($output)->not->toContain('Read first');
});

it('reports the file count, what changed and how long it took', function (): void {
    $state = (new WatchState(snapshot()))->withSnapshot(snapshot(), ['app/Services/Report.php'], 1.24);

    expect(frame($state))->toContain('61 files')
        ->and(frame($state))->toContain('Report.php')
        ->and(frame($state))->toContain('1.2s');
});

it('names a count rather than every file when a burst lands', function (): void {
    $state = (new WatchState(snapshot()))->withSnapshot(snapshot(), ['app/A.php', 'app/B.php', 'app/C.php'], 0.5);

    expect(frame($state))->toContain('3 files changed');
});

it('offers keypresses when the terminal can read them', function (): void {
    expect(frame(new WatchState(snapshot()), keypresses: true))->toContain('↑↓ move')
        ->and(frame(new WatchState(snapshot()), keypresses: true))->toContain('q quit');
});

it('offers only Ctrl+C when the terminal has no keypresses to give', function (): void {
    // Windows has no raw console mode in PHP, so the hints must not promise
    // keys that will never arrive.
    $output = frame(new WatchState(snapshot(top: [topRow()])), keypresses: false);

    expect($output)->not->toContain('↑↓ move')
        ->and($output)->not->toContain('q quit')
        ->and($output)->toContain('Ctrl+C');
});

it('keeps every line inside the terminal width', function (): void {
    $state = new WatchState(snapshot(top: [topRow([
        'file' => 'app/Domain/Billing/Infrastructure/Persistence/Doctrine/VeryLongRepositoryName.php',
        'name' => 'An Extremely Long Rule Name That Will Not Fit Anywhere',
    ])]));

    foreach ((new FrameRenderer(60))->render($state) as $line) {
        expect(mb_strlen(strip_tags($line)))->toBeLessThanOrEqual(60);
    }
});

it('escapes text quoted from the analysed project', function (): void {
    // A file called <p>.php is source text, not console markup.
    $output = frame(new WatchState(snapshot(top: [topRow(['file' => 'app/<p>.php'])])));

    expect($output)->toContain('app/\<p\>.php');
});
