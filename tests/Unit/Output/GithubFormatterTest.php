<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Location;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Output\GithubFormatter;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

it('escapes a newline and a percent so a finding cannot terminate its own annotation', function (): void {
    $f = new Finding(
        'SL900',
        'Test Rule',
        Category::Complexity,
        Severity::Critical,
        90,
        new Location('app/A.php', 5),
        "Multi\nline message with 100% coverage claim.",
        'e',
        'Fix it now.',
        'fp',
    );

    $result = AnalysisResult::create([$f], ['app/A.php'], 100, new ScoreCalculator);
    $out = (new GithubFormatter)->format($result);

    // A workflow command is exactly one line: everything the annotation must
    // say has to fit on it, so a raw newline in the message is what would let
    // a finding write a second, unparsed command into the log.
    $lines = explode(PHP_EOL, rtrim($out, PHP_EOL));

    expect($lines)->toHaveCount(2)
        ->and($out)->toContain('%0A')
        ->not->toContain("Multi\nline")
        ->and($out)->toContain('100%25 coverage')
        ->not->toContain('100% coverage');
});

it('escapes a comma and a colon in the file path so they cannot break the property list', function (): void {
    $f = finding(file: 'app/Invoice, Copy: 2.php');
    $result = AnalysisResult::create([$f], ['app/A.php'], 100, new ScoreCalculator);

    $out = (new GithubFormatter)->format($result);

    expect($out)->toContain('file=app/Invoice%2C Copy%3A 2.php')
        ->not->toContain('file=app/Invoice, Copy: 2.php');
});

it('maps severity to error, warning or notice workflow commands', function (): void {
    $findings = [
        finding(rule: 'SL1', fingerprint: 'a', severity: Severity::Critical),
        finding(rule: 'SL2', fingerprint: 'b', severity: Severity::High),
        finding(rule: 'SL3', fingerprint: 'c', severity: Severity::Medium),
        finding(rule: 'SL4', fingerprint: 'd', severity: Severity::Low),
        finding(rule: 'SL5', fingerprint: 'e', severity: Severity::Info),
    ];

    $result = AnalysisResult::create($findings, ['app/A.php'], 100, new ScoreCalculator);
    $out = (new GithubFormatter)->format($result);

    expect($out)->toContain('::error file=')
        ->and(substr_count($out, '::error '))->toBe(2)
        ->and(substr_count($out, '::warning '))->toBe(1)
        ->and(substr_count($out, '::notice '))->toBe(3); // low + info findings, plus the trailing score notice
});

it('names skipped rules as a notice and prints the score summary last', function (): void {
    $result = new AnalysisResult(
        findings: [finding()],
        analyzedFiles: ['app/A.php'],
        analyzedLines: 100,
        score: (new ScoreCalculator)->calculate([finding()], 100),
        errors: [],
        skippedRules: ['SL201', 'SL203'],
    );

    $out = (new GithubFormatter)->format($result);
    $lines = explode(PHP_EOL, rtrim($out, PHP_EOL));

    expect($out)->toContain('2 rule(s) skipped for a missing framework: SL201, SL203.')
        ->and(end($lines))->toContain('::notice title=Sloppy score::');
});
