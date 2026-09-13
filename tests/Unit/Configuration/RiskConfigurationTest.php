<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\RiskConfiguration;

it('grows reach by the documented amount at the documented radii', function (): void {
    $config = new RiskConfiguration;

    expect(round($config->reachFor(1), 2))->toBe(1.30)
        ->and(round($config->reachFor(10), 2))->toBe(2.04)
        ->and(round($config->reachFor(100), 2))->toBe(3.00);
});

it('treats an unmeasured blast radius as exactly 1.0, never as a claim about reach', function (): void {
    $config = new RiskConfiguration;

    expect($config->reachFor(null))->toBe(1.0)
        ->and($config->reachFor(-1))->toBe(1.0);
});

it('flattens reach to 1.0 for every radius when reach_weight is zero', function (): void {
    $config = new RiskConfiguration(reachWeight: 0.0);

    expect($config->reachFor(1))->toBe(1.0)
        ->and($config->reachFor(10))->toBe(1.0)
        ->and($config->reachFor(100))->toBe(1.0)
        ->and($config->reachWeight())->toBe(0.0);
});

it('discriminates reach at different radii once reach_weight is non-zero', function (): void {
    $config = new RiskConfiguration;

    $small = $config->reachFor(1);
    $medium = $config->reachFor(10);
    $large = $config->reachFor(100);

    expect($small)->toBeLessThan($medium)
        ->and($medium)->toBeLessThan($large);
});

it('reads severity weights and reach weight from an array', function (): void {
    $config = RiskConfiguration::fromArray([
        'severity_weights' => ['high' => 99.0, 'low' => 3],
        'reach_weight' => 2.5,
    ]);

    expect($config->weightFor(Severity::High))->toBe(99.0)
        ->and($config->weightFor(Severity::Low))->toBe(3.0)
        ->and($config->reachWeight())->toBe(2.5);
});

it('falls back to the documented default weights and reach weight when the array is empty or malformed', function (): void {
    $config = RiskConfiguration::fromArray([
        'severity_weights' => 'not-an-array',
        'reach_weight' => 'not-a-number',
    ]);

    $defaults = new RiskConfiguration;

    expect($config->weightFor(Severity::High))->toBe($defaults->weightFor(Severity::High))
        ->and($config->weightFor(Severity::Critical))->toBe($defaults->weightFor(Severity::Critical))
        ->and($config->reachWeight())->toBe($defaults->reachWeight());
});

it('clamps a negative configured reach weight to zero rather than inverting reach', function (): void {
    $config = RiskConfiguration::fromArray(['reach_weight' => -5]);

    expect($config->reachWeight())->toBe(0.0);
});

it('answers 1.0 for a severity it has no configured weight for', function (): void {
    $config = RiskConfiguration::fromArray(['severity_weights' => ['high' => 10.0]]);

    expect($config->weightFor(Severity::Info))->toBe(1.0);
});
