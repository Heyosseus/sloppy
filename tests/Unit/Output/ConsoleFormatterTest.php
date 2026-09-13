<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Output\ConsoleFormatter;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

it('reports a clean run', function (): void {
    $result = AnalysisResult::create([], ['app/A.php'], 500, new ScoreCalculator);
    $output = (new ConsoleFormatter)->format($result);

    expect($output)->toContain('Sloppy')
        ->toContain('100/100')
        ->toContain('Clean')
        ->toContain('Nothing flagged.')
        ->toContain('1 analysed');
});

it('says a clean run passed when a threshold is set', function (): void {
    $result = AnalysisResult::create([], ['app/A.php'], 500, new ScoreCalculator);
    $output = (new ConsoleFormatter(failOn: Severity::High))->format($result);

    expect($output)->toContain('Nothing flagged.')
        ->toContain('Passed')
        ->toContain('nothing at high or above');
});

it('groups findings by file and shows message, rule and confidence', function (): void {
    $result = AnalysisResult::create([
        finding(rule: 'SL101', file: 'app/A.php', line: 42, confidence: 96),
        finding(rule: 'SL203', file: 'app/B.php', line: 7, fingerprint: 'b', severity: Severity::Medium),
    ], ['app/A.php', 'app/B.php'], 1000, new ScoreCalculator);

    $output = (new ConsoleFormatter)->format($result);

    expect($output)->toContain('app/A.php')
        ->toContain('app/B.php')
        ->toContain('SL101')
        ->toContain('God Method')
        ->toContain('96% confidence')
        ->toContain('A message.')
        ->toContain('A suggestion.')
        ->toContain('2 findings');
});

it('withholds the long explanation unless asked', function (): void {
    $result = AnalysisResult::create([finding()], ['app/A.php'], 1000, new ScoreCalculator);

    expect((new ConsoleFormatter)->format($result))->not->toContain('An explanation.')
        ->and((new ConsoleFormatter(explain: true))->format($result))->toContain('An explanation.');
});

it('states pass or fail against a threshold', function (): void {
    $breaching = AnalysisResult::create([finding(severity: Severity::High)], ['app/A.php'], 1000, new ScoreCalculator);
    $clear = AnalysisResult::create([finding(severity: Severity::Low)], ['app/A.php'], 1000, new ScoreCalculator);

    expect((new ConsoleFormatter(failOn: Severity::High))->format($breaching))
        ->toContain('✗ Failed')
        ->toContain('1 finding(s) at high or above')
        ->and((new ConsoleFormatter(failOn: Severity::High))->format($clear))
        ->toContain('✓ Passed');
});

it('says nothing about pass or fail when there is no threshold', function (): void {
    $result = AnalysisResult::create([finding()], ['app/A.php'], 1000, new ScoreCalculator);
    $output = (new ConsoleFormatter)->format($result);

    expect($output)->not->toContain('Failed')
        ->not->toContain('Passed');
});

it('surfaces files it could not analyse', function (): void {
    $result = AnalysisResult::create([], ['app/A.php'], 100, new ScoreCalculator, [
        'app/Broken.php' => 'Syntax error, unexpected EOF',
    ]);

    expect((new ConsoleFormatter)->format($result))
        ->toContain('Could not be analysed')
        ->toContain('app/Broken.php')
        ->toContain('Syntax error, unexpected EOF');
});

it('names the rules it skipped', function (): void {
    $result = AnalysisResult::create([], ['app/A.php'], 500, new ScoreCalculator, skippedRules: ['SL201', 'SL203']);

    expect((new ConsoleFormatter)->format($result))->toContain('2 rule(s) skipped: SL201, SL203');
});

it('withholds the risk arithmetic unless asked, and prints it under --explain-risk', function (): void {
    $result = AnalysisResult::create(
        [finding(confidence: 76, severity: Severity::High, metrics: ['blast_radius' => 1])],
        ['app/A.php'],
        1000,
        new ScoreCalculator,
    );

    $plain = (new ConsoleFormatter)->format($result);
    $explained = (new ConsoleFormatter(explainRisk: true))->format($result);

    expect($plain)->not->toContain('risk  ')
        ->and($explained)->toContain(
            'risk  10.0 (high) x 0.76 (confidence) x 1.00 (novelty unknown) x 1.00 (whole file) x 1.30 (1 usage) = 9.89',
        );
});

it('counts one finding in the singular', function (): void {
    $one = (new ConsoleFormatter)->format(AnalysisResult::create([finding()], ['app/A.php'], 1000, new ScoreCalculator));
    $two = (new ConsoleFormatter)->format(AnalysisResult::create(
        [finding(fingerprint: 'a'), finding(fingerprint: 'b')],
        ['app/A.php'],
        1000,
        new ScoreCalculator,
    ));

    expect($one)->toContain('·  1 finding')
        ->not->toContain('1 findings')
        ->and($two)->toContain('·  2 findings');
});
