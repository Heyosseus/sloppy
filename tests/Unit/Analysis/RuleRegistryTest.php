<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Contracts\Rule;
use Heyosseus\Sloppy\Rules\BaseRule;

/**
 * A custom rule, standing in for one a host application would register.
 */
final class NeverFiresRule extends BaseRule
{
    public function id(): string
    {
        return 'APP001';
    }

    public function name(): string
    {
        return 'Never Fires';
    }

    public function description(): string
    {
        return 'A rule that exists to prove custom registration works.';
    }

    public function category(): Category
    {
        return Category::Architecture;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Info;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        return [];
    }
}

it('ships twenty-four rules with unique ids', function (): void {
    $registry = RuleRegistry::withDefaults();
    $ids = $registry->ids();

    expect($ids)->toHaveCount(24)
        ->and(array_unique($ids))->toHaveCount(24)
        ->and($registry->count())->toBe(24);
});

it('gives every rule an id, name, description, explanation and category', function (): void {
    foreach (RuleRegistry::withDefaults()->rules() as $rule) {
        expect($rule->id())->toMatch('/^SL\d{3}$/')
            ->and($rule->name())->not->toBe('')
            ->and($rule->description())->not->toBe('')
            ->and($rule->explanation())->not->toBe('')
            ->and($rule->category())->toBeInstanceOf(Category::class)
            ->and($rule->severity())->toBeInstanceOf(Severity::class);
    }
});

it('finds a rule by id', function (): void {
    $registry = RuleRegistry::withDefaults();

    expect($registry->get('SL101')?->name())->toBe('God Method')
        ->and($registry->get('SL999'))->toBeNull();
});

it('drops rules the configuration disables', function (): void {
    $registry = RuleRegistry::fromConfiguration(Configuration::fromArray([
        'framework' => 'laravel',
        'rules' => ['SL109' => ['enabled' => false], 'SL110' => ['enabled' => false]],
    ], '/project'));

    expect($registry->ids())->not->toContain('SL109')
        ->not->toContain('SL110')
        ->and($registry->count())->toBe(22);
});

it('applies per-rule options', function (): void {
    $registry = RuleRegistry::fromConfiguration(Configuration::fromArray([
        'rules' => ['SL101' => ['severity' => 'low']],
    ], '/project'));

    expect($registry->get('SL101')?->severity())->toBe(Severity::Low)
        ->and($registry->get('SL102')?->severity())->toBe(Severity::High);
});

it('registers custom rules from configuration', function (): void {
    $registry = RuleRegistry::fromConfiguration(Configuration::fromArray([
        'framework' => 'laravel',
        'custom_rules' => [NeverFiresRule::class],
    ], '/project'));

    expect($registry->count())->toBe(25)
        ->and($registry->get('APP001'))->toBeInstanceOf(NeverFiresRule::class);
});

it('can disable a custom rule too', function (): void {
    $registry = RuleRegistry::fromConfiguration(Configuration::fromArray([
        'custom_rules' => [NeverFiresRule::class],
        'rules' => ['APP001' => ['enabled' => false]],
    ], '/project'));

    expect($registry->get('APP001'))->toBeNull();
});

it('narrows to specific rule ids, case insensitively', function (): void {
    $registry = RuleRegistry::withDefaults()->only(['sl101', 'SL203']);

    expect($registry->ids())->toBe(['SL101', 'SL203'])
        ->and(RuleRegistry::withDefaults()->only([])->count())->toBe(0);
});

it('keeps the skipped list when narrowing to specific rule ids', function (): void {
    $project = tempProject(['composer.json' => '{"require":{"nikic/php-parser":"^5.3"}}']);
    $registry = RuleRegistry::fromConfiguration(Configuration::fromArray([], $project))->only(['SL101']);

    expect($registry->ids())->toBe(['SL101'])
        ->and($registry->skipped())->toBe([
            'SL201', 'SL202', 'SL203', 'SL204', 'SL205',
            'SL206', 'SL207', 'SL208', 'SL209', 'SL210',
        ]);

    removeTree($project);
});

it('can be built from an explicit list', function (): void {
    $registry = RuleRegistry::of([new NeverFiresRule]);

    expect($registry->ids())->toBe(['APP001'])
        ->and($registry->rules()[0])->toBeInstanceOf(Rule::class);
});

it('has an entry in the shipped config for every shipped rule', function (): void {
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__, 3).'/config/sloppy.php';

    /** @var array<string, mixed> $rules */
    $rules = $config['rules'];

    foreach (RuleRegistry::withDefaults()->ids() as $id) {
        expect($rules)->toHaveKey($id);
    }

    // And nothing in the config refers to a rule that no longer exists.
    expect(array_diff(array_keys($rules), RuleRegistry::withDefaults()->ids()))->toBe([]);
});

it('skips the laravel rules in a project that is not laravel, and names them', function (): void {
    $project = tempProject(['composer.json' => '{"require":{"nikic/php-parser":"^5.3"}}']);
    $registry = RuleRegistry::fromConfiguration(Configuration::fromArray([], $project));

    expect($registry->ids())->not->toContain('SL203')
        ->and($registry->ids())->toContain('SL101')
        ->and($registry->skipped())->toBe([
            'SL201', 'SL202', 'SL203', 'SL204', 'SL205',
            'SL206', 'SL207', 'SL208', 'SL209', 'SL210',
        ]);

    removeTree($project);
});

it('runs every rule in a laravel project', function (): void {
    $project = tempProject(['composer.json' => '{"require":{"laravel/framework":"^12.0"}}']);
    $registry = RuleRegistry::fromConfiguration(Configuration::fromArray([], $project));

    expect($registry->count())->toBe(24)
        ->and($registry->skipped())->toBe([]);

    removeTree($project);
});
