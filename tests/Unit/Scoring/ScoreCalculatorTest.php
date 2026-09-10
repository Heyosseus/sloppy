<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\ScoreConfiguration;
use Heyosseus\Sloppy\Scoring\ScoreBand;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

it('gives clean code a perfect score', function (): void {
    $score = (new ScoreCalculator)->calculate([], 5000);

    expect($score->value)->toBe(100)
        ->and($score->band)->toBe(ScoreBand::Clean)
        ->and($score->label())->toBe('Clean')
        ->and((string) $score)->toBe('100/100')
        ->and($score->penalty)->toBe(0.0);
});

it('is deterministic', function (): void {
    $findings = [
        finding(fingerprint: 'a', severity: Severity::High),
        finding(fingerprint: 'b', severity: Severity::Medium),
        finding(fingerprint: 'c', severity: Severity::Low),
    ];

    $calculator = new ScoreCalculator;

    expect($calculator->calculate($findings, 2000)->value)
        ->toBe($calculator->calculate($findings, 2000)->value);
});

it('weighs a low-confidence finding less than a certain one', function (): void {
    $calculator = new ScoreCalculator;

    $certain = $calculator->calculate([finding(confidence: 100)], 1000);
    $unsure = $calculator->calculate([finding(confidence: 30)], 1000);

    expect($unsure->value)->toBeGreaterThan($certain->value)
        ->and($unsure->penalty)->toBeLessThan($certain->penalty);
});

it('normalises by codebase size so large projects are not punished for being large', function (): void {
    $calculator = new ScoreCalculator;
    $findings = array_map(
        static fn (int $i): Heyosseus\Sloppy\Analysis\Finding => finding(fingerprint: 'f'.$i, severity: Severity::High),
        range(1, 5),
    );

    $small = $calculator->calculate($findings, 500);
    $large = $calculator->calculate($findings, 20000);

    expect($large->value)->toBeGreaterThan($small->value);
});

it('penalises findings that blanket the codebase more than localised ones', function (): void {
    $calculator = new ScoreCalculator;

    $localised = $calculator->calculate([finding(line: 1, endLine: 5)], 1000);
    $sprawling = $calculator->calculate([finding(line: 1, endLine: 1000)], 1000);

    expect($sprawling->value)->toBeLessThan($localised->value);
});

it('never falls below zero or rises above one hundred', function (): void {
    $calculator = new ScoreCalculator;
    $many = array_map(
        static fn (int $i): Heyosseus\Sloppy\Analysis\Finding => finding(line: 1, endLine: 100, fingerprint: 'f'.$i, severity: Severity::Critical),
        range(1, 200),
    );

    expect($calculator->calculate($many, 100)->value)->toBe(0)
        ->and($calculator->calculate([], 0)->value)->toBe(100);
});

it('maps scores onto the documented bands', function (): void {
    $config = new ScoreConfiguration;

    expect($config->bandFor(100))->toBe(ScoreBand::Clean)
        ->and($config->bandFor(90))->toBe(ScoreBand::Clean)
        ->and($config->bandFor(89))->toBe(ScoreBand::Healthy)
        ->and($config->bandFor(75))->toBe(ScoreBand::Healthy)
        ->and($config->bandFor(74))->toBe(ScoreBand::NeedsAttention)
        ->and($config->bandFor(60))->toBe(ScoreBand::NeedsAttention)
        ->and($config->bandFor(59))->toBe(ScoreBand::Sloppy)
        ->and($config->bandFor(40))->toBe(ScoreBand::Sloppy)
        ->and($config->bandFor(39))->toBe(ScoreBand::Severe)
        ->and($config->bandFor(0))->toBe(ScoreBand::Severe);
});

it('lets bands be reconfigured', function (): void {
    $config = ScoreConfiguration::fromArray([
        'bands' => ['clean' => 99, 'healthy' => 95, 'needs_attention' => 90, 'sloppy' => 80],
    ]);

    expect($config->bandFor(98))->toBe(ScoreBand::Healthy)
        ->and($config->bandFor(85))->toBe(ScoreBand::Sloppy)
        ->and($config->bandFor(79))->toBe(ScoreBand::Severe);
});

it('lets weights and the multiplier be reconfigured', function (): void {
    $harsh = new ScoreCalculator(ScoreConfiguration::fromArray([
        'weights' => ['high' => 40.0],
        'penalty_multiplier' => 2.0,
    ]));

    $gentle = new ScoreCalculator(ScoreConfiguration::fromArray([
        'weights' => ['high' => 1.0],
    ]));

    $findings = [finding(severity: Severity::High)];

    expect($harsh->calculate($findings, 1000)->value)->toBeLessThan($gentle->calculate($findings, 1000)->value);
});

it('falls back to sane defaults for malformed configuration', function (): void {
    $config = ScoreConfiguration::fromArray([
        'weights' => 'nonsense',
        'bands' => 'nonsense',
        'lines_per_unit' => -5,
        'penalty_multiplier' => 'nonsense',
    ]);

    expect($config->linesPerUnit)->toBe(1000)
        ->and($config->penaltyMultiplier)->toBe(1.0)
        ->and($config->weightFor(Severity::High))->toBe(Severity::High->defaultWeight())
        ->and($config->bandFor(95))->toBe(ScoreBand::Clean);
});

it('ignores non-numeric weights and non-integer band thresholds', function (): void {
    $config = ScoreConfiguration::fromArray([
        'weights' => ['high' => 'lots', 'medium' => 2],
        'bands' => ['clean' => 'most', 'healthy' => 70],
    ]);

    expect($config->weightFor(Severity::High))->toBe(Severity::High->defaultWeight())
        ->and($config->weightFor(Severity::Medium))->toBe(2.0)
        ->and($config->bandFor(95))->toBe(ScoreBand::Clean)
        ->and($config->bandFor(72))->toBe(ScoreBand::Healthy);
});

it('exports the numbers behind the score', function (): void {
    $array = (new ScoreCalculator)->calculate([finding()], 1000)->toArray();

    expect($array)->toHaveKeys(['value', 'band', 'label', 'penalty', 'penalty_density']);
});

it('has a colour for every band', function (): void {
    foreach (ScoreBand::cases() as $band) {
        expect($band->color())->not->toBe('')
            ->and($band->label())->not->toBe('');
    }
});
