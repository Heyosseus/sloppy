<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Integrations\HealthSnapshot;
use Heyosseus\Sloppy\Scoring\ScoreBand;

it('summarises a run into the numbers a dashboard needs', function (): void {
    $snapshot = HealthSnapshot::from(analysisResult([
        finding(rule: 'SL101', severity: Severity::High, category: Category::Complexity),
        finding(rule: 'SL107', file: 'app/Pay.php', fingerprint: 'Pay::run', severity: Severity::Critical, category: Category::ErrorHandling),
        finding(rule: 'SL104', file: 'app/Pay.php', fingerprint: 'Pay::two', severity: Severity::Low, category: Category::Complexity),
    ], files: ['app/Order.php', 'app/Pay.php'], lines: 800), generatedAt: 1_700_000_000);

    expect($snapshot->findings)->toBe(3)
        ->and($snapshot->files)->toBe(2)
        ->and($snapshot->lines)->toBe(800)
        ->and($snapshot->generatedAt)->toBe(1_700_000_000)
        ->and($snapshot->bySeverity)->toBe([
            'critical' => 1,
            'high' => 1,
            'medium' => 0,
            'low' => 1,
            'info' => 0,
        ])
        ->and($snapshot->isClean())->toBeFalse()
        ->and($snapshot->band)->toBeInstanceOf(ScoreBand::class)
        ->and($snapshot->label())->toBe($snapshot->band->label());
});

it('orders categories by size, then by the enum, so the chart is stable', function (): void {
    $snapshot = HealthSnapshot::from(analysisResult([
        finding(rule: 'SL107', fingerprint: 'a', category: Category::ErrorHandling),
        finding(rule: 'SL101', fingerprint: 'b', category: Category::Complexity),
        finding(rule: 'SL101', fingerprint: 'c', category: Category::Complexity),
        finding(rule: 'SL104', fingerprint: 'd', category: Category::Duplication),
    ]));

    expect(array_keys($snapshot->byCategory))->toBe(['complexity', 'duplication', 'error-handling'])
        ->and($snapshot->byCategory['complexity'])->toBe(2);
});

it('keeps only the requested number of ranked findings', function (): void {
    $findings = [];

    foreach (range(1, 8) as $index) {
        $findings[] = finding(line: $index, fingerprint: 'Order::method'.$index);
    }

    $snapshot = HealthSnapshot::from(analysisResult($findings), top: 3);

    expect($snapshot->top)->toHaveCount(3)
        ->and($snapshot->top[0])->toHaveKeys(['rule', 'name', 'severity', 'file', 'line', 'message', 'risk'])
        ->and($snapshot->top[0]['risk'])->toBeGreaterThan(0.0);
});

it('asks for no findings at all when told to rank none', function (): void {
    $snapshot = HealthSnapshot::from(analysisResult([finding()]), top: 0);

    expect($snapshot->top)->toBe([]);
});

it('stamps itself with the current time when none is given', function (): void {
    $before = time();
    $snapshot = HealthSnapshot::from(analysisResult());

    expect($snapshot->generatedAt)->toBeGreaterThanOrEqual($before)
        ->and($snapshot->isClean())->toBeTrue()
        ->and($snapshot->isStale(3600))->toBeFalse()
        ->and($snapshot->isStale(0))->toBeTrue();
});

it('survives a round trip through its own array form', function (): void {
    $snapshot = HealthSnapshot::from(analysisResult([
        finding(severity: Severity::Critical),
    ], skippedRules: ['SL201']), generatedAt: 1_700_000_000);

    $restored = HealthSnapshot::fromArray($snapshot->toArray());

    expect($restored->score)->toBe($snapshot->score)
        ->and($restored->band)->toBe($snapshot->band)
        ->and($restored->findings)->toBe($snapshot->findings)
        ->and($restored->files)->toBe($snapshot->files)
        ->and($restored->lines)->toBe($snapshot->lines)
        ->and($restored->bySeverity)->toBe($snapshot->bySeverity)
        ->and($restored->byCategory)->toBe($snapshot->byCategory)
        ->and($restored->top)->toBe($snapshot->top)
        ->and($restored->skippedRules)->toBe(['SL201'])
        ->and($restored->generatedAt)->toBe(1_700_000_000);
});

it('degrades to an empty snapshot rather than crashing a dashboard', function (): void {
    $snapshot = HealthSnapshot::fromArray([
        'score' => 'seventy',
        'band' => 'not-a-band',
        'by_severity' => 'nonsense',
        'by_category' => ['complexity' => 'two', 'duplication' => 1],
        'top' => ['not-a-row', ['rule' => 'SL101', 'line' => '12', 'risk' => 4]],
        'rules_skipped' => ['SL201', 42],
    ]);

    expect($snapshot->score)->toBe(100)
        ->and($snapshot->band)->toBe(ScoreBand::Clean)
        ->and($snapshot->bySeverity)->toBe([])
        ->and($snapshot->byCategory)->toBe(['duplication' => 1])
        ->and($snapshot->top)->toHaveCount(1)
        ->and($snapshot->top[0]['rule'])->toBe('SL101')
        ->and($snapshot->top[0]['line'])->toBe(0)
        ->and($snapshot->top[0]['risk'])->toBe(4.0)
        ->and($snapshot->top[0]['message'])->toBe('')
        ->and($snapshot->skippedRules)->toBe(['SL201'])
        ->and($snapshot->generatedAt)->toBe(0);
});

it('counts findings at or above a severity', function (): void {
    $snapshot = HealthSnapshot::from(analysisResult([
        finding(fingerprint: 'a', severity: Severity::Critical),
        finding(fingerprint: 'b', severity: Severity::High),
        finding(fingerprint: 'c', severity: Severity::Low),
    ]));

    expect($snapshot->countAtOrAbove(Severity::High))->toBe(2)
        ->and($snapshot->countAtOrAbove(Severity::Info))->toBe(3)
        ->and($snapshot->countAtOrAbove(Severity::Critical))->toBe(1);
});

it('ignores a severity it does not recognise when counting', function (): void {
    $snapshot = HealthSnapshot::fromArray(['by_severity' => ['critical' => 2, 'catastrophic' => 9]]);

    expect($snapshot->countAtOrAbove(Severity::High))->toBe(2);
});

it('says its state in one line a menu bar can show', function (): void {
    $snapshot = HealthSnapshot::from(analysisResult([finding()], files: ['app/Order.php'], lines: 400));

    expect($snapshot->summary())->toContain('/100')
        ->and($snapshot->summary())->toContain('1 finding(s) across 1 file(s)');
});
