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
        static fn (int $i): Heyosseus\Sloppy\Analysis\Finding => finding(rule: 'SL'.(100 + $i % 10), line: 1, endLine: 100, fingerprint: 'f'.$i, severity: Severity::Critical),
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

it('counts a line covered by two findings once', function (): void {
    $calculator = new ScoreCalculator(new ScoreConfiguration(ruleCap: 0.0));

    // A god method inside a god class: the method's lines are already the
    // class's, so the pair covers 100 lines, not 150.
    $nested = $calculator->calculate([
        finding(rule: 'SL102', line: 1, endLine: 100, fingerprint: 'class'),
        finding(rule: 'SL101', line: 20, endLine: 69, fingerprint: 'method'),
    ], 1000);

    $apart = $calculator->calculate([
        finding(rule: 'SL102', line: 1, endLine: 100, fingerprint: 'class'),
        finding(rule: 'SL101', file: 'app/Other.php', line: 20, endLine: 69, fingerprint: 'method'),
    ], 1000);

    // 2 x 9.0 penalty per unit, x (1 + 100/1000) nested, x (1 + 150/1000) apart.
    expect($nested->value)->toBe(80)
        ->and($apart->value)->toBe(79);
});

it('gives each rule diminishing returns up to the rule cap', function (): void {
    $findings = array_map(
        static fn (int $i): Heyosseus\Sloppy\Analysis\Finding => finding(fingerprint: 'f'.$i, confidence: 100),
        range(1, 30),
    );

    $uncapped = (new ScoreCalculator(new ScoreConfiguration(ruleCap: 0.0)))->calculate($findings, 1000);
    $capped = (new ScoreCalculator)->calculate($findings, 1000);

    // Thirty certain high findings in 1,000 lines deduct 300 points unchecked,
    // and one rule alone can take at most 30.
    expect($uncapped->value)->toBe(0)
        ->and($capped->value)->toBe(70);
});

it('barely changes a small deduction', function (): void {
    $findings = [finding(confidence: 100)];

    $uncapped = (new ScoreCalculator(new ScoreConfiguration(ruleCap: 0.0)))->calculate($findings, 1000);
    $capped = (new ScoreCalculator)->calculate($findings, 1000);

    expect($uncapped->value)->toBe(90)
        ->and($capped->value)->toBe(91);
});

it('reads the rule cap from configuration and rejects a negative one', function (): void {
    expect(ScoreConfiguration::fromArray(['rule_cap' => 12])->ruleCap)->toBe(12.0)
        ->and(ScoreConfiguration::fromArray(['rule_cap' => 0])->ruleCap)->toBe(0.0)
        ->and(ScoreConfiguration::fromArray(['rule_cap' => -1])->ruleCap)->toBe(ScoreConfiguration::RULE_CAP)
        ->and(ScoreConfiguration::fromArray([])->ruleCap)->toBe(30.0);
});
