<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\DiffHunk;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Output\ReviewFormatter;
use Heyosseus\Sloppy\Scoring\Score;
use Heyosseus\Sloppy\Scoring\ScoreBand;

/**
 * @param  list<ChangedFile>  $changedFiles
 * @param  list<Heyosseus\Sloppy\Analysis\Finding>  $new
 * @param  list<Heyosseus\Sloppy\Analysis\Finding>  $existing
 * @param  list<Heyosseus\Sloppy\Analysis\Finding>  $resolved
 */
function reviewReport(
    array $changedFiles,
    array $new = [],
    array $existing = [],
    array $resolved = [],
): DiffReport {
    return new DiffReport(
        base: 'HEAD',
        changedFiles: $changedFiles,
        new: $new,
        existing: $existing,
        resolved: $resolved,
        currentScore: new Score(80, ScoreBand::Healthy, 0.0, 0.0),
        baseScore: new Score(85, ScoreBand::Healthy, 0.0, 0.0),
    );
}

it('sorts a file above the skim threshold into Read and one below it into Skim', function (): void {
    // Critical, full confidence, "in hunk" via an added file: risk 20.0.
    $bigFinding = finding(rule: 'SL600', file: 'app/Big.php', fingerprint: 'big', confidence: 100, severity: Severity::Critical);
    // Low, full confidence: risk 1.5 -- comfortably under the 5.0 threshold.
    $smallFinding = finding(rule: 'SL601', file: 'app/Small.php', fingerprint: 'small', confidence: 100, severity: Severity::Low);

    $report = reviewReport(
        changedFiles: [
            new ChangedFile('app/Big.php', 'added'),
            new ChangedFile('app/Small.php', 'added'),
        ],
        new: [$bigFinding, $smallFinding],
    );

    $out = (new ReviewFormatter)->format($report);

    expect($out)->toContain('Read in this order')
        ->and($out)->toContain('app/Big.php')
        ->and(mb_strpos($out, 'app/Big.php'))->toBeLessThan((int) mb_strpos($out, 'Skim'))
        ->and($out)->toContain('Skim')
        ->and(mb_strpos($out, 'Skim'))->toBeLessThan((int) mb_strpos($out, 'app/Small.php'))
        ->and($out)->toContain('risk 1.5');
});

it('counts a changed file with no findings under "No attention needed"', function (): void {
    $bigFinding = finding(rule: 'SL600', file: 'app/Big.php', fingerprint: 'big', confidence: 100, severity: Severity::Critical);

    $report = reviewReport(
        changedFiles: [
            new ChangedFile('app/Big.php', 'added'),
            new ChangedFile('app/Quiet.php', 'modified'),
        ],
        new: [$bigFinding],
    );

    $out = (new ReviewFormatter)->format($report);

    expect($out)->toContain('No attention needed')
        ->and($out)->toContain('1 file(s) changed with no findings')
        // A quiet file is counted, never named or listed under Read or Skim.
        ->not->toContain('app/Quiet.php');
});

it('shows only four findings from a busy file and a truthful tail naming the rest by rule', function (): void {
    // Six same-risk findings in one file: the four with the lowest line
    // numbers are shown (rank ties break ascending by line), the remaining
    // two -- both SL104 -- are summarised in the tail.
    $findings = [
        finding(rule: 'SL101', file: 'app/Busy.php', line: 1, fingerprint: 'b1', confidence: 90, severity: Severity::High),
        finding(rule: 'SL102', file: 'app/Busy.php', line: 2, fingerprint: 'b2', confidence: 90, severity: Severity::High),
        finding(rule: 'SL103', file: 'app/Busy.php', line: 3, fingerprint: 'b3', confidence: 90, severity: Severity::High),
        finding(rule: 'SL105', file: 'app/Busy.php', line: 4, fingerprint: 'b4', confidence: 90, severity: Severity::High),
        finding(rule: 'SL104', file: 'app/Busy.php', line: 5, fingerprint: 'b5', confidence: 90, severity: Severity::High),
        finding(rule: 'SL104', file: 'app/Busy.php', line: 6, fingerprint: 'b6', confidence: 90, severity: Severity::High),
    ];

    $report = reviewReport(
        changedFiles: [new ChangedFile('app/Busy.php', 'added')],
        new: $findings,
    );

    $out = (new ReviewFormatter)->format($report);

    expect($out)->toContain('SL101')
        ->toContain('SL102')
        ->toContain('SL103')
        ->toContain('SL105')
        ->toContain('and 2 more in this file, 2 x SL104');

    // The tail summarises SL104 rather than listing it, so its id must not
    // appear as one of the four shown findings above the tail line.
    $beforeTail = explode('and 2 more', $out)[0];
    expect($beforeTail)->not->toContain('SL104');
});

it('summarises resolved findings by rule, counted and named', function (): void {
    $resolved = [
        finding(rule: 'SL700', file: 'app/Old.php', line: 1, fingerprint: 'r1', name: 'Swallowed Exception'),
        finding(rule: 'SL700', file: 'app/Old.php', line: 2, fingerprint: 'r2', name: 'Swallowed Exception'),
        finding(rule: 'SL701', file: 'app/Old2.php', line: 1, fingerprint: 'r3', name: 'Dead Private Method'),
    ];

    $report = reviewReport(changedFiles: [], resolved: $resolved);

    $out = (new ReviewFormatter)->format($report);

    expect($out)->toContain('Resolved by this change')
        ->toContain('3 finding(s) no longer reported')
        ->toContain('2 x SL700 Swallowed Exception')
        ->toContain('1 x SL701 Dead Private Method');
});

it('says nothing needs reading when there is nothing above zero risk', function (): void {
    $report = reviewReport(changedFiles: [new ChangedFile('app/Quiet.php', 'modified')]);

    $out = (new ReviewFormatter)->format($report);

    expect($out)->toContain('Nothing in this change needs reading.')
        ->not->toContain('Read in this order')
        ->not->toContain('Skim');
});

/*
 * Proximity: the one thing nothing else can do. A finding the change
 * created and an identical finding it merely stood next to are not the same
 * finding, and only the diff's own hunks can tell them apart.
 */
it('ranks a finding inside the changed hunk above an identical one outside it, by exactly the documented ratio', function (): void {
    // The hunk covers lines 10-14.
    $hunk = new DiffHunk(startLine: 10, lineCount: 5);
    $changedFile = new ChangedFile('app/Prox.php', 'modified', [$hunk]);

    // Same rule, same severity, same confidence -- the only difference is
    // which line each one is reported on.
    $inHunk = finding(rule: 'SL500', file: 'app/Prox.php', line: 12, fingerprint: 'in-hunk', confidence: 90, severity: Severity::High);
    $nearby = finding(rule: 'SL500', file: 'app/Prox.php', line: 30, fingerprint: 'nearby', confidence: 90, severity: Severity::High);

    // Both reported as "new" so novelty is identical for both too --
    // proximity is the only variable left standing.
    $report = reviewReport(changedFiles: [$changedFile], new: [$inHunk, $nearby]);

    $out = (new ReviewFormatter)->format($report);

    // The in-hunk finding is rendered first (higher risk) and tagged; the
    // nearby one is rendered second and is not tagged "in hunk".
    $order = mb_strpos($out, 'risk 9.0');
    $nearbyPosition = mb_strpos($out, 'risk 2.7');

    expect($order)->not->toBeFalse()
        ->and($nearbyPosition)->not->toBeFalse()
        ->and($order)->toBeLessThan($nearbyPosition)
        ->and($out)->toContain('risk 9.0, in hunk')
        ->and($out)->not->toContain('risk 2.7, in hunk');

    // 9.0 / 2.7 is exactly the documented in-hunk/nearby ratio of 1.0 / 0.3.
    expect(round(9.0 / 2.7, 4))->toBe(round(
        Heyosseus\Sloppy\Configuration\RiskConfiguration::PROXIMITY_IN_HUNK
        / Heyosseus\Sloppy\Configuration\RiskConfiguration::PROXIMITY_NEARBY,
        4,
    ));
});

it('treats an added or untracked file as touched everywhere, with no hunks needed', function (): void {
    $addedFile = new ChangedFile('app/New.php', 'added');
    $f = finding(rule: 'SL800', file: 'app/New.php', line: 500, fingerprint: 'new', confidence: 90, severity: Severity::High);

    $report = reviewReport(changedFiles: [$addedFile], new: [$f]);

    $out = (new ReviewFormatter)->format($report);

    expect($out)->toContain('in hunk');
});

it('renders the same review as markdown, with no console markup left in it', function (): void {
    // `review --format=markdown` produces a pull-request comment. Before this
    // existed the markdown format fell through to the console renderer and
    // published raw `<options=bold>` tags into the comment body, which is worse
    // than not supporting it at all.
    $report = reviewReport(
        changedFiles: [new ChangedFile('app/Reporting.php', 'modified', [new DiffHunk(40, 20)])],
        new: [
            finding(rule: 'SL101', file: 'app/Reporting.php', line: 42, confidence: 90, severity: Severity::High),
            finding(rule: 'SL107', file: 'app/Reporting.php', line: 44, confidence: 80, severity: Severity::High),
        ],
    );

    $markdown = (new ReviewFormatter(markdown: true))->format($report);
    $console = (new ReviewFormatter)->format($report);

    expect($markdown)
        // Markdown structure, not indented bold lines.
        ->toContain('## Sloppy review')
        ->toContain('### Read in this order')
        ->toContain('**SL101**')
        ->toContain('`app/Reporting.php`')
        // And not one scrap of Symfony console markup.
        ->not->toContain('<options=bold>')
        ->not->toContain('<fg=')
        ->not->toContain('</>')
        // The console rendering is unchanged and still uses that markup, so
        // this is a real branch rather than markdown having replaced it.
        ->and($console)->toContain('<options=bold>')
        // Both describe the same change: the decoration differs, the content
        // does not.
        ->and($console)->toContain('Read in this order')
        ->and($markdown)->toContain('Read in this order');
});

it('keeps the risk arithmetic readable as markdown code under --explain-risk', function (): void {
    $report = reviewReport(
        changedFiles: [new ChangedFile('app/Reporting.php', 'modified', [new DiffHunk(40, 20)])],
        new: [finding(rule: 'SL101', file: 'app/Reporting.php', line: 42, confidence: 90, severity: Severity::High)],
    );

    $with = (new ReviewFormatter(explainRisk: true, markdown: true))->format($report);
    $without = (new ReviewFormatter(markdown: true))->format($report);

    // The multiplication signs would be read as emphasis if the arithmetic were
    // not wrapped, so it goes in backticks.
    expect($with)->toContain('`10.0 (high)')
        ->toContain('(in hunk)')
        ->and($without)->not->toContain('10.0 (high)');
});
