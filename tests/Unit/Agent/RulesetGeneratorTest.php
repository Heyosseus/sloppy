<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\RuleGuidance;
use Heyosseus\Sloppy\Agent\RulesetFormat;
use Heyosseus\Sloppy\Agent\RulesetGenerator;
use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Contracts\Rule;
use Heyosseus\Sloppy\Sloppy;

/**
 * @param  array<string, mixed>  $config
 */
function generatorFor(array $config = []): RulesetGenerator
{
    return RulesetGenerator::for(new Sloppy(Configuration::fromArray(
        [...['framework' => 'laravel', 'fail_on' => 'high'], ...$config],
        sys_get_temp_dir(),
    )));
}

it('writes every rule in force, with what to do instead', function (): void {
    $ruleset = generatorFor()->generate(RulesetFormat::Claude);

    expect($ruleset)->toContain('# Sloppy: code rules for this repository')
        ->and($ruleset)->toContain('Instructions for Claude Code working in this repository.')
        ->and($ruleset)->toContain('## The 24 rules in force')
        ->and($ruleset)->toContain('### SL101 God Method')
        ->and($ruleset)->toContain('### SL210 Suspicious Model::all()')
        ->and($ruleset)->toContain('- Write it this way instead: Give a method one job.')
        ->and($ruleset)->toContain('vendor/bin/sloppy diff main --fail-on=high')
        ->and($ruleset)->toContain('- Fails on: high and above')
        ->and($ruleset)->toContain('- Confidence floor: 0');
});

it('describes the project rather than the defaults', function (): void {
    $ruleset = generatorFor([
        'paths' => ['src', 'domain'],
        'fail_on' => 'critical',
        'min_confidence' => 70,
    ])->generate(RulesetFormat::Cursor);

    expect($ruleset)->toContain('Instructions for Cursor working in this repository.')
        ->and($ruleset)->toContain('- Analysed paths: src, domain')
        ->and($ruleset)->toContain('- Fails on: critical and above')
        ->and($ruleset)->toContain('- Confidence floor: 70')
        ->and($ruleset)->toContain('--fail-on=critical');
});

it('says "never" when the project fails on nothing', function (): void {
    expect(generatorFor(['fail_on' => null])->generate(RulesetFormat::Agents))
        ->toContain('- Fails on: never and above');
});

it('names the rules that do not apply, so nothing is written to satisfy them', function (): void {
    $ruleset = generatorFor(['framework' => 'none'])->generate(RulesetFormat::Claude);

    expect($ruleset)->toContain('## Not in force here')
        ->and($ruleset)->toContain('SL201')
        ->and($ruleset)->toContain('they describe a framework it does not use')
        ->and($ruleset)->toContain('## The 14 rules in force');
});

it('leaves out the section entirely when every rule applies', function (): void {
    expect(generatorFor()->generate(RulesetFormat::Claude))->not->toContain('## Not in force here');
});

it('writes the same rules as structured JSON', function (): void {
    /** @var array{schema: int, version: string, project: array<string, mixed>, rules: list<array<string, string>>, rules_not_in_force: list<string>} $decoded */
    $decoded = json_decode(generatorFor()->generate(RulesetFormat::Json), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['schema'])->toBe(1)
        ->and($decoded['version'])->toBe(Sloppy::VERSION)
        ->and($decoded['project']['fail_on'])->toBe('high')
        ->and($decoded['rules'])->toHaveCount(24)
        ->and($decoded['rules'][0])->toHaveKeys(['id', 'name', 'category', 'severity', 'description', 'explanation', 'guidance'])
        ->and($decoded['rules_not_in_force'])->toBe([]);
});

it('produces the same bytes twice, so a generated file has no spurious diff', function (): void {
    expect(generatorFor()->generate(RulesetFormat::Claude))->toBe(generatorFor()->generate(RulesetFormat::Claude));
});

it('has advice for every shipped rule', function (): void {
    foreach (RuleRegistry::withDefaults()->rules() as $rule) {
        expect(RuleGuidance::has($rule->id()))->toBeTrue(sprintf('%s has no guidance.', $rule->id()))
            ->and(RuleGuidance::for($rule))->not->toBe($rule->description());
    }
});

it('falls back to a rule\'s own description when it is not one of ours', function (): void {
    $rule = new class extends Heyosseus\Sloppy\Rules\BaseRule
    {
        public function id(): string
        {
            return 'APP001';
        }

        public function name(): string
        {
            return 'No Facades In Domain';
        }

        public function description(): string
        {
            return 'Domain code reaches for a facade.';
        }

        public function category(): Heyosseus\Sloppy\Analysis\Category
        {
            return Heyosseus\Sloppy\Analysis\Category::Architecture;
        }

        public function analyze(Heyosseus\Sloppy\Analysis\AnalysisContext $context): iterable
        {
            return [];
        }

        protected function defaultSeverity(): Heyosseus\Sloppy\Analysis\Severity
        {
            return Heyosseus\Sloppy\Analysis\Severity::Medium;
        }
    };

    expect(RuleGuidance::for($rule))->toBe('Domain code reaches for a facade.')
        ->and(RuleGuidance::has('APP001'))->toBeFalse();

    $generator = new RulesetGenerator(
        RuleRegistry::of([$rule]),
        Configuration::fromArray([], sys_get_temp_dir()),
    );

    expect($generator->generate(RulesetFormat::Markdown))->toContain('### APP001 No Facades In Domain')
        ->and($generator->generate(RulesetFormat::Markdown))->toContain('The 1 rules in force');
});

it('names a rule by its id in every format it writes', function (): void {
    foreach (RulesetFormat::cases() as $format) {
        $ruleset = generatorFor()->generate($format);

        expect($ruleset)->toContain('SL101')
            ->and($ruleset)->toContain($format->preamble())
            ->and($format->defaultFile())->not->toBe('')
            ->and($format->label())->not->toBe('');
    }
});

it('takes the rules from the registry it was handed', function (): void {
    $registry = RuleRegistry::withDefaults()->only(['SL101', 'SL107']);
    $generator = new RulesetGenerator($registry, Configuration::fromArray([], sys_get_temp_dir()), '9.9.9');

    $ruleset = $generator->generate(RulesetFormat::Windsurf);

    expect($ruleset)->toContain('## The 2 rules in force')
        ->and($ruleset)->toContain('Generated by Sloppy 9.9.9')
        ->and(array_map(static fn (Rule $rule): string => $rule->id(), $registry->rules()))->toBe(['SL101', 'SL107']);
});
