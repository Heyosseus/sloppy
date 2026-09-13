<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Location;
use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Output\SarifFormatter;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

/**
 * @return array<string, mixed>
 */
function decodedSarif(AnalysisResult $result): array
{
    $sarif = (new SarifFormatter)->format($result);
    $decoded = json_decode($sarif, true, flags: JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

it('decodes as valid JSON at version 2.1.0', function (): void {
    $result = AnalysisResult::create([finding()], ['app/A.php'], 100, new ScoreCalculator);
    $decoded = decodedSarif($result);

    expect($decoded['version'])->toBe('2.1.0')
        ->and($decoded)->toHaveKey('runs');
});

it('describes only the rules that actually fired, not the full shipped registry', function (): void {
    $result = AnalysisResult::create([
        finding(rule: 'SL101', fingerprint: 'a'),
        finding(rule: 'SL204', fingerprint: 'b', name: 'Query Inside Loop'),
    ], ['app/A.php'], 100, new ScoreCalculator);

    $decoded = decodedSarif($result);
    $rules = $decoded['runs'][0]['tool']['driver']['rules'];

    $ids = array_column($rules, 'id');
    sort($ids);

    expect($ids)->toBe(['SL101', 'SL204'])
        ->and(count($rules))->toBeLessThan(count(RuleRegistry::withDefaults()->rules()));
});

it('falls back to the finding explanation for a rule id the registry does not know', function (): void {
    $result = AnalysisResult::create([
        finding(rule: 'SL999', name: 'Made Up Rule'),
    ], ['app/A.php'], 100, new ScoreCalculator);

    $rules = decodedSarif($result)['runs'][0]['tool']['driver']['rules'];

    expect($rules[0]['fullDescription']['text'])->toBe('An explanation.');
});

it('maps severity to the SARIF levels the spec defines', function (): void {
    $findings = [
        new Finding('SL1', 'N', Category::Complexity, Severity::Critical, 90, new Location('app/A.php', 1), 'm', 'e', 's', 'critical-fp'),
        new Finding('SL2', 'N', Category::Complexity, Severity::High, 90, new Location('app/A.php', 2), 'm', 'e', 's', 'high-fp'),
        new Finding('SL3', 'N', Category::Complexity, Severity::Medium, 90, new Location('app/A.php', 3), 'm', 'e', 's', 'medium-fp'),
        new Finding('SL4', 'N', Category::Complexity, Severity::Low, 90, new Location('app/A.php', 4), 'm', 'e', 's', 'low-fp'),
        new Finding('SL5', 'N', Category::Complexity, Severity::Info, 90, new Location('app/A.php', 5), 'm', 'e', 's', 'info-fp'),
    ];

    $result = AnalysisResult::create($findings, ['app/A.php'], 100, new ScoreCalculator);
    $results = decodedSarif($result)['runs'][0]['results'];

    $levelByRule = [];

    foreach ($results as $entry) {
        $levelByRule[$entry['ruleId']] = $entry['level'];
    }

    expect($levelByRule)->toBe([
        'SL1' => 'error',
        'SL2' => 'error',
        'SL3' => 'warning',
        'SL4' => 'note',
        'SL5' => 'note',
    ]);
});

it('carries Finding::identity() as the sloppyIdentity/v1 partial fingerprint', function (): void {
    $f = finding(rule: 'SL101', file: 'app/Order.php', fingerprint: 'Order::store');
    $result = AnalysisResult::create([$f], ['app/Order.php'], 100, new ScoreCalculator);

    $entry = decodedSarif($result)['runs'][0]['results'][0];

    expect($entry['partialFingerprints']['sloppyIdentity/v1'])->toBe($f->identity())
        ->and($f->identity())->not->toBeEmpty();
});

it('reports a skipped rule as an invocation notification', function (): void {
    $result = new AnalysisResult(
        findings: [],
        analyzedFiles: ['app/A.php'],
        analyzedLines: 10,
        score: (new ScoreCalculator)->calculate([], 10),
        errors: [],
        skippedRules: ['SL201', 'SL203'],
    );

    $notifications = decodedSarif($result)['runs'][0]['invocations'][0]['toolExecutionNotifications'];

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['message']['text'])->toContain('SL201, SL203')
        ->and($notifications[0]['level'])->toBe('note');
});

it('produces no rule notification and executionSuccessful=true when nothing was skipped or errored', function (): void {
    $result = AnalysisResult::create([], ['app/A.php'], 100, new ScoreCalculator);

    $decoded = decodedSarif($result);

    expect($decoded['runs'][0]['invocations'][0]['toolExecutionNotifications'])->toBe([])
        ->and($decoded['runs'][0]['invocations'][0]['executionSuccessful'])->toBeTrue();
});
