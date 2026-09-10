<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

/**
 * @param  list<Heyosseus\Sloppy\Analysis\Finding>  $findings
 */
function resultOf(array $findings, int $lines = 1000): AnalysisResult
{
    return AnalysisResult::create($findings, ['app/A.php', 'app/B.php'], $lines, new ScoreCalculator);
}

it('sorts by severity, then file, then line, then rule', function (): void {
    $result = resultOf([
        finding(rule: 'SL109', file: 'app/B.php', line: 5, fingerprint: 'b', severity: Severity::Low),
        finding(rule: 'SL101', file: 'app/B.php', line: 5, fingerprint: 'c', severity: Severity::High),
        finding(rule: 'SL101', file: 'app/A.php', line: 90, fingerprint: 'd', severity: Severity::High),
        finding(rule: 'SL101', file: 'app/A.php', line: 10, fingerprint: 'e', severity: Severity::High),
    ]);

    expect(array_map(
        static fn (Heyosseus\Sloppy\Analysis\Finding $f): string => $f->location->relativePath.':'.$f->location->line.' '.$f->severity->value,
        $result->findings,
    ))->toBe([
        'app/A.php:10 high',
        'app/A.php:90 high',
        'app/B.php:5 high',
        'app/B.php:5 low',
    ]);
});

it('produces the same order for the same findings shuffled', function (): void {
    $findings = [
        finding(rule: 'SL101', file: 'app/A.php', line: 10, fingerprint: 'a'),
        finding(rule: 'SL203', file: 'app/A.php', line: 20, fingerprint: 'b', severity: Severity::Medium),
        finding(rule: 'SL109', file: 'app/B.php', line: 30, fingerprint: 'c', severity: Severity::Low),
    ];

    $forwards = AnalysisResult::sort($findings);
    $backwards = AnalysisResult::sort(array_reverse($findings));

    expect(array_map(static fn (Heyosseus\Sloppy\Analysis\Finding $f): string => $f->fingerprint, $forwards))
        ->toBe(array_map(static fn (Heyosseus\Sloppy\Analysis\Finding $f): string => $f->fingerprint, $backwards));
});

it('counts every severity, including the empty ones', function (): void {
    $result = resultOf([
        finding(fingerprint: 'a', severity: Severity::High),
        finding(fingerprint: 'b', severity: Severity::High),
        finding(fingerprint: 'c', severity: Severity::Low),
    ]);

    expect($result->countsBySeverity())->toBe([
        'critical' => 0,
        'high' => 2,
        'medium' => 0,
        'low' => 1,
        'info' => 0,
    ])
        ->and($result->count())->toBe(3)
        ->and($result->fileCount())->toBe(2)
        ->and($result->isEmpty())->toBeFalse();
});

it('groups findings by file', function (): void {
    $result = resultOf([
        finding(file: 'app/A.php', fingerprint: 'a'),
        finding(file: 'app/A.php', fingerprint: 'b'),
        finding(file: 'app/B.php', fingerprint: 'c'),
    ]);

    expect(array_keys($result->groupedByFile()))->toBe(['app/A.php', 'app/B.php'])
        ->and($result->groupedByFile()['app/A.php'])->toHaveCount(2);
});

it('filters by threshold', function (): void {
    $result = resultOf([
        finding(fingerprint: 'a', severity: Severity::Critical),
        finding(fingerprint: 'b', severity: Severity::High),
        finding(fingerprint: 'c', severity: Severity::Medium),
        finding(fingerprint: 'd', severity: Severity::Info),
    ]);

    expect($result->atOrAbove(Severity::High))->toHaveCount(2)
        ->and($result->hasAtOrAbove(Severity::High))->toBeTrue()
        ->and($result->hasAtOrAbove(Severity::Critical))->toBeTrue()
        ->and(resultOf([finding(severity: Severity::Low)])->hasAtOrAbove(Severity::High))->toBeFalse();
});

it('recalculates the score when findings are replaced', function (): void {
    $result = resultOf([
        finding(fingerprint: 'a', severity: Severity::Critical),
        finding(fingerprint: 'b', severity: Severity::Critical),
    ]);

    $cleared = $result->withFindings([], new ScoreCalculator);

    expect($result->score->value)->toBeLessThan(100)
        ->and($cleared->score->value)->toBe(100)
        ->and($cleared->analyzedFiles)->toBe($result->analyzedFiles)
        ->and($cleared->analyzedLines)->toBe($result->analyzedLines);
});

it('exports a stable report shape', function (): void {
    $array = resultOf([finding()])->toArray();

    expect($array)->toHaveKeys(['score', 'summary', 'findings', 'errors'])
        ->and($array['summary'])->toHaveKeys(['files', 'lines', 'findings', 'by_severity']);
});

it('carries errors through', function (): void {
    $result = AnalysisResult::create([], [], 0, new ScoreCalculator, ['app/Broken.php' => 'Syntax error']);

    expect($result->errors)->toBe(['app/Broken.php' => 'Syntax error'])
        ->and($result->withFindings([], new ScoreCalculator)->errors)->toBe($result->errors);
});
