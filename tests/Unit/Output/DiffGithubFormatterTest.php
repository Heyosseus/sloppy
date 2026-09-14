<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\DiffHunk;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Output\DiffGithubFormatter;
use Heyosseus\Sloppy\Scoring\Score;
use Heyosseus\Sloppy\Scoring\ScoreBand;

/**
 * @param  list<Finding>  $new
 * @param  list<Finding>  $existing
 * @param  list<Finding>  $resolved
 */
function annotatedReport(array $new = [], array $existing = [], array $resolved = [], int $current = 80): DiffReport
{
    return new DiffReport(
        base: 'origin/main',
        changedFiles: [new ChangedFile('app/Order.php', 'modified', [new DiffHunk(10, 5)])],
        new: $new,
        existing: $existing,
        resolved: $resolved,
        currentScore: new Score($current, ScoreBand::Healthy, 0.0, 0.0),
        baseScore: new Score(85, ScoreBand::Healthy, 0.0, 0.0),
    );
}

it('annotates only what the change introduced', function (): void {
    $report = annotatedReport(
        new: [finding(rule: 'SL107', line: 12, severity: Severity::Critical)],
        existing: [finding(rule: 'SL101', file: 'app/Old.php', line: 4, fingerprint: 'Old::run')],
    );

    $output = (new DiffGithubFormatter)->format($report);

    expect($output)->toContain('::error file=app/Order.php,line=12')
        ->and($output)->toContain('Sloppy SL107')
        ->and($output)->not->toContain('app/Old.php');
});

it('summarises the comparison in a notice a reviewer can read at a glance', function (): void {
    $report = annotatedReport(
        new: [finding()],
        existing: [finding(fingerprint: 'b'), finding(fingerprint: 'c')],
        resolved: [finding(fingerprint: 'd')],
    );

    expect((new DiffGithubFormatter)->format($report))->toContain(
        '::notice title=Sloppy diff::Against origin/main: 1 new, 2 inherited, 1 resolved '
        .'across 1 changed file(s). Score 80/100 (-5).'
    );
});

it('marks an improvement with a plus', function (): void {
    expect((new DiffGithubFormatter)->format(annotatedReport(current: 90)))->toContain('Score 90/100 (+5).');
});

it('ends with exactly one newline after the notice', function (): void {
    $output = (new DiffGithubFormatter)->format(annotatedReport(new: [finding()]));

    expect($output)->toEndWith(PHP_EOL)
        ->and($output)->not->toEndWith(PHP_EOL.PHP_EOL);
});

it('hands the new findings to a formatter as a run of their own', function (): void {
    $report = annotatedReport(new: [finding()], existing: [finding(fingerprint: 'b')]);

    expect($report->newResult()->count())->toBe(1)
        ->and($report->newResult()->analyzedFiles)->toBe(['app/Order.php'])
        ->and($report->newResult()->analyzedLines)->toBe(5)
        ->and($report->newResult()->score->value)->toBe(80)
        ->and($report->currentResult()->count())->toBe(2);
});
