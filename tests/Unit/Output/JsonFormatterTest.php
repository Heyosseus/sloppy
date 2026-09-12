<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\ScoreConfiguration;
use Heyosseus\Sloppy\Output\JsonFormatter;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

/**
 * @return array<string, mixed>
 */
function decodedReport(AnalysisResult $result): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((new JsonFormatter)->format($result), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

it('emits the documented top-level contract', function (): void {
    $report = decodedReport(AnalysisResult::create([finding()], ['app/A.php'], 1000, new ScoreCalculator));

    expect($report)->toHaveKeys(['schema', 'tool', 'score', 'summary', 'findings', 'errors', 'rules_skipped'])
        ->and($report['schema'])->toBe(JsonFormatter::SCHEMA)
        ->and($report['tool'])->toBe('sloppy');
});

it('emits every field of every finding', function (): void {
    $report = decodedReport(AnalysisResult::create(
        [finding(endLine: 60, metrics: ['lines' => 171])],
        ['app/A.php'],
        1000,
        new ScoreCalculator,
    ));

    expect($report['findings'][0])->toMatchArray([
        'rule' => 'SL101',
        'name' => 'God Method',
        'category' => 'complexity',
        'severity' => 'high',
        'confidence' => 90,
        'file' => 'app/Order.php',
        'line' => 10,
        'end_line' => 60,
        'fingerprint' => 'Order::store',
        'metrics' => ['lines' => 171],
    ]);
});

it('is byte-identical for the same input', function (): void {
    $result = AnalysisResult::create(
        [finding(fingerprint: 'a'), finding(fingerprint: 'b', severity: Severity::Low)],
        ['app/A.php'],
        1000,
        new ScoreCalculator,
    );

    $formatter = new JsonFormatter;

    expect($formatter->format($result))->toBe($formatter->format($result));
});

it('does not escape slashes in paths', function (): void {
    $json = (new JsonFormatter)->format(AnalysisResult::create([finding()], ['app/A.php'], 1000, new ScoreCalculator));

    expect($json)->toContain('"app/Order.php"')
        ->not->toContain('app\/Order.php');
});

it('can emit compact output', function (): void {
    $result = AnalysisResult::create([finding()], ['app/A.php'], 1000, new ScoreCalculator);

    $pretty = (new JsonFormatter)->format($result);
    $compact = (new JsonFormatter(pretty: false))->format($result);

    expect(mb_strlen($compact))->toBeLessThan(mb_strlen($pretty))
        ->and(json_decode($compact, true))->toBe(json_decode($pretty, true));
});

it('reports the score and summary numbers', function (): void {
    $report = decodedReport(AnalysisResult::create(
        [finding(severity: Severity::High), finding(fingerprint: 'b', severity: Severity::Low)],
        ['app/A.php', 'app/B.php'],
        2000,
        new ScoreCalculator,
    ));

    expect($report['summary'])->toMatchArray([
        'files' => 2,
        'lines' => 2000,
        'findings' => 2,
    ])
        ->and($report['summary']['by_severity'])->toMatchArray(['high' => 1, 'low' => 1])
        ->and($report['score'])->toHaveKeys(['value', 'band', 'label', 'penalty', 'penalty_density']);
});

it('names the rules it skipped', function (): void {
    $result = new AnalysisResult(
        findings: [],
        analyzedFiles: ['app/A.php'],
        analyzedLines: 10,
        score: (new ScoreCalculator(new ScoreConfiguration))->calculate([], 10),
        errors: [],
        skippedRules: ['SL201', 'SL203'],
    );

    /** @var array{rules_skipped: list<string>} $decoded */
    $decoded = json_decode((new JsonFormatter)->format($result), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['rules_skipped'])->toBe(['SL201', 'SL203']);
});
