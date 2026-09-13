<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Contracts\Rule;

/**
 * @param  array<string, mixed>  $values
 */
function config_(array $values = [], string $base = '/project'): Configuration
{
    return Configuration::fromArray($values, $base);
}

it('normalises the base path', function (): void {
    expect(config_(base: 'C:\\projects\\shop\\')->basePath)->toBe('C:/projects/shop')
        ->and(config_(base: '/srv/app/')->basePath)->toBe('/srv/app');
});

it('defaults to analysing app', function (): void {
    expect(config_()->paths())->toBe(['app'])
        ->and(config_(['paths' => []])->paths())->toBe(['app'])
        ->and(config_(['paths' => ['src', ' domain ']])->paths())->toBe(['src', 'domain'])
        ->and(config_(['paths' => 'nonsense'])->paths())->toBe(['app']);
});

it('is enabled unless told otherwise', function (): void {
    expect(config_()->enabled())->toBeTrue()
        ->and(config_(['enabled' => false])->enabled())->toBeFalse()
        ->and(config_(['enabled' => 'yes'])->enabled())->toBeTrue();
});

it('reads the failure threshold, including the ways of disabling it', function (): void {
    expect(config_(['fail_on' => 'high'])->failOn())->toBe(Severity::High)
        ->and(config_(['fail_on' => 'MEDIUM'])->failOn())->toBe(Severity::Medium)
        ->and(config_(['fail_on' => null])->failOn())->toBeNull()
        ->and(config_(['fail_on' => false])->failOn())->toBeNull()
        ->and(config_(['fail_on' => 'never'])->failOn())->toBeNull()
        ->and(config_()->failOn())->toBeNull();
});

it('rejects a non-string failure threshold', function (): void {
    expect(fn (): ?Severity => config_(['fail_on' => 3])->failOn())
        ->toThrow(InvalidArgumentException::class, 'sloppy.fail_on');
});

it('clamps the confidence floor', function (): void {
    expect(config_(['min_confidence' => 150])->minConfidence())->toBe(100)
        ->and(config_(['min_confidence' => -5])->minConfidence())->toBe(0)
        ->and(config_(['min_confidence' => 70])->minConfidence())->toBe(70)
        ->and(config_()->minConfidence())->toBe(0);
});

it('resolves the baseline path against the project root', function (): void {
    expect(config_()->baselinePath())->toBe('/project/.sloppy-baseline.json')
        ->and(config_(['baseline' => 'build/base.json'])->baselinePath())->toBe('/project/build/base.json')
        ->and(config_(['baseline' => '/tmp/base.json'])->baselinePath())->toBe('/tmp/base.json')
        ->and(config_(['baseline' => 'C:\\tmp\\base.json'])->baselinePath())->toBe('C:\tmp\base.json');
});

it('treats every rule as enabled until told otherwise', function (): void {
    $config = config_(['rules' => [
        'SL109' => ['enabled' => false],
        'SL101' => ['max_lines' => 40],
    ]]);

    expect($config->isRuleEnabled('SL101'))->toBeTrue()
        ->and($config->isRuleEnabled('SL999'))->toBeTrue()
        ->and($config->isRuleEnabled('SL109'))->toBeFalse();
});

it('reads per-rule options and drops non-string keys', function (): void {
    $config = config_(['rules' => [
        'SL101' => ['max_lines' => 40, 7 => 'ignored'],
        'SL102' => 'nonsense',
    ]]);

    expect($config->ruleOptions('SL101'))->toBe(['max_lines' => 40])
        ->and($config->ruleOptions('SL102'))->toBe([])
        ->and($config->ruleOptions('SL999'))->toBe([]);
});

it('matches exclusions as path fragments and globs', function (): void {
    expect(config_(['exclude' => ['storage', '*.blade.php']])->exclude())
        ->toBe(['storage', '*.blade.php']);
});

it('validates custom rule classes', function (): void {
    expect(config_(['custom_rules' => []])->customRules())->toBe([])
        ->and(fn (): array => config_(['custom_rules' => [stdClass::class]])->customRules())
        ->toThrow(InvalidArgumentException::class, 'must implement '.Rule::class);
});

it('returns immutable copies when overridden', function (): void {
    $original = config_(['paths' => ['app'], 'fail_on' => 'high', 'min_confidence' => 0]);
    $changed = $original->withPaths(['src'])->withFailOn(Severity::Low)->withMinConfidence(50);

    expect($original->paths())->toBe(['app'])
        ->and($original->failOn())->toBe(Severity::High)
        ->and($original->minConfidence())->toBe(0)
        ->and($changed->paths())->toBe(['src'])
        ->and($changed->failOn())->toBe(Severity::Low)
        ->and($changed->minConfidence())->toBe(50)
        ->and($changed->withFailOn(null)->failOn())->toBeNull();
});

it('reads score configuration', function (): void {
    $score = config_(['score' => ['lines_per_unit' => 500, 'penalty_multiplier' => 2.0]])->score();

    expect($score->linesPerUnit)->toBe(500)
        ->and($score->penaltyMultiplier)->toBe(2.0);
});

it('reads risk configuration, a separate question from the score', function (): void {
    $risk = config_(['risk' => ['reach_weight' => 2.0, 'severity_weights' => ['high' => 50.0]]])->risk();

    expect($risk->reachWeight())->toBe(2.0)
        ->and($risk->weightFor(Severity::High))->toBe(50.0);
});

it('defaults risk configuration when none is given', function (): void {
    $risk = config_()->risk();

    expect($risk->reachFor(null))->toBe(1.0)
        ->and($risk->reachWeight())->toBe(1.0);
});

it('defaults the framework to auto and reads it from composer.json', function (): void {
    $project = tempProject(['composer.json' => '{"require":{"laravel/framework":"^12.0"}}']);
    $config = Configuration::fromArray([], $project);

    expect($config->framework())->toBe('auto')
        ->and($config->hasFramework('laravel'))->toBeTrue();

    removeTree($project);
});

it('honours an explicitly pinned framework over what composer.json says', function (): void {
    $project = tempProject(['composer.json' => '{"require":{"laravel/framework":"^12.0"}}']);

    expect(Configuration::fromArray(['framework' => 'none'], $project)->hasFramework('laravel'))->toBeFalse()
        ->and(Configuration::fromArray(['framework' => 'laravel'], '/nowhere')->hasFramework('laravel'))->toBeTrue();

    removeTree($project);
});
