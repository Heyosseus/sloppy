<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Location;

it('clamps confidence into 0-100', function (): void {
    expect(finding(confidence: 150)->confidence)->toBe(100)
        ->and(finding(confidence: -20)->confidence)->toBe(0)
        ->and(finding(confidence: 73)->confidence)->toBe(73);
});

it('gives the same finding the same identity regardless of line', function (): void {
    // This is what lets a baseline survive an added import.
    expect(finding(line: 10)->identity())->toBe(finding(line: 400)->identity());
});

it('gives different rules, files or fingerprints different identities', function (): void {
    $base = finding();

    expect($base->identity())->not->toBe(finding(rule: 'SL102')->identity())
        ->and($base->identity())->not->toBe(finding(file: 'app/Other.php')->identity())
        ->and($base->identity())->not->toBe(finding(fingerprint: 'Order::index')->identity());
});

it('scales its penalty by confidence', function (): void {
    expect(finding(confidence: 100)->weightedPenalty(10.0))->toBe(10.0)
        ->and(finding(confidence: 50)->weightedPenalty(10.0))->toBe(5.0)
        ->and(finding(confidence: 0)->weightedPenalty(10.0))->toBe(0.0);
});

it('measures the lines it affects', function (): void {
    expect(finding(line: 10, endLine: 30)->location->affectedLines())->toBe(21)
        ->and(finding(line: 10)->location->affectedLines())->toBe(1)
        // A nonsensical range falls back to one line rather than going negative.
        ->and(finding(line: 30, endLine: 10)->location->affectedLines())->toBe(1);
});

it('renders a location as file:line', function (): void {
    expect((string) new Location('app/A.php', 12))->toBe('app/A.php:12')
        ->and((string) new Location('app/A.php', 12, 20, 5))->toBe('app/A.php:12:5');
});

it('exports every documented field', function (): void {
    $array = finding(endLine: 40, metrics: ['lines' => 171])->toArray();

    expect($array)->toHaveKeys([
        'rule', 'name', 'category', 'severity', 'confidence', 'file', 'line',
        'end_line', 'column', 'message', 'explanation', 'suggestion',
        'fingerprint', 'identity', 'metrics',
    ])
        ->and($array['rule'])->toBe('SL101')
        ->and($array['severity'])->toBe('high')
        ->and($array['category'])->toBe('complexity')
        ->and($array['metrics'])->toBe(['lines' => 171]);
});
