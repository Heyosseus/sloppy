<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\ConfigSchema;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\ConfigurationResolver;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\ScanOptions;
use Heyosseus\Sloppy\Runner\ScanRunner;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

/**
 * A configuration says what it means or says why it cannot be read -- never
 * quietly something else.
 */
it('accepts the shipped configuration without a single problem', function (): void {
    /** @var array<string, mixed> $shipped */
    $shipped = require dirname(__DIR__, 3).'/config/sloppy.php';

    expect(Configuration::fromArray($shipped, '/project')->problems)->toBe([]);
});

it('accepts every PHP example in the configuration docs', function (): void {
    $docs = (string) file_get_contents(dirname(__DIR__, 3).'/docs/configuration.md');

    preg_match_all('/^\s*\'([a-z_]+)\' =>/m', $docs, $matches);

    $schema = new ReflectionClassConstant(ConfigSchema::class, 'TOP_LEVEL');
    $known = [
        ...(array) $schema->getValue(),
        ...array_merge(...array_values(array_map(array_keys(...), ConfigSchema::RULE_OPTIONS))),
    ];

    // Every top-level key the docs show is one the schema knows; nested keys
    // (architecture roles, matchers) belong to the profile loader.
    foreach (['enabled', 'paths', 'exclude', 'fail_on', 'min_confidence', 'baseline', 'rules', 'architecture', 'custom_rules'] as $key) {
        expect($known)->toContain($key);
    }

    expect(array_values(array_diff(
        array_filter($matches[1], static fn (string $key): bool => str_starts_with($key, 'max_') || str_starts_with($key, 'min_') || str_starts_with($key, 'ignore_')),
        $known,
    )))->toBe([]);
});

it('knows every option every shipped rule reads', function (): void {
    $root = dirname(__DIR__, 3).'/src';
    $missing = [];

    foreach ([...glob($root.'/Rules/*/*.php') ?: [], ...glob($root.'/Evidence/*.php') ?: []] as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match("/function id\(\): string\s*\{\s*return '(SL\d+)'/", $source, $id) !== 1) {
            continue;
        }

        preg_match_all("/(?:int|float|bool|list|string)Option\('([a-z_]+)'/", $source, $options);

        foreach ($options[1] as $option) {
            if (! isset(ConfigSchema::RULE_OPTIONS[$id[1]][$option])) {
                $missing[] = $id[1].'.'.$option;
            }
        }
    }

    $evidence = (string) file_get_contents($root.'/Evidence/EvidenceCollector.php');
    preg_match_all("/stringsFrom\(\\\$config, '(SL\d+)', '([a-z_]+)'/", $evidence, $read, PREG_SET_ORDER);

    foreach ($read as [, $rule, $option]) {
        if (! isset(ConfigSchema::RULE_OPTIONS[$rule][$option])) {
            $missing[] = $rule.'.'.$option;
        }
    }

    expect($read)->not->toBe([])
        ->and($missing)->toBe([]);
});

it('reads values that mean one obvious thing in that meaning', function (): void {
    $config = Configuration::fromArray([
        'paths' => 'lib',
        'min_confidence' => '90',
        'enabled' => 'true',
        'fail_on' => 'NONE',
        'rules' => ['SL107' => ['enabled' => 'false'], 'SL101' => ['max_lines' => '120']],
        'score' => ['penalty_multiplier' => '1.5'],
    ], '/project');

    expect($config->problems)->toBe([])
        ->and($config->paths())->toBe(['lib'])
        ->and($config->minConfidence())->toBe(90)
        ->and($config->enabled())->toBeTrue()
        ->and($config->failOn())->toBeNull()
        ->and($config->isRuleEnabled('SL107'))->toBeFalse()
        ->and($config->ruleOptions('SL101'))->toBe(['max_lines' => 120])
        ->and($config->score()->penaltyMultiplier)->toBe(1.5);
});

it('names every problem instead of falling back to a default', function (): void {
    $config = Configuration::fromArray([
        'fial_on' => 'high',
        'paths' => 42,
        'min_confidence' => 'lots',
        'rules' => [
            'SL10' => [],
            'SL101' => ['max_line' => 80, 'enabled' => 'sometimes', 'severity' => 'huge'],
            'SL102' => 'off',
        ],
        'score' => ['weights' => ['high' => -1.0], 'penalty_multiplier' => -2, 'bandz' => []],
        'risk' => ['reach_weight' => -1],
        'health' => ['ttl' => -5],
    ], '/project');

    $problems = implode("\n", $config->problems);

    $unknown = implode("\n", $config->unknownSettings);

    expect($unknown)->toContain('sloppy.fial_on is not a setting Sloppy knows (did you mean fail_on?)')
        ->and($unknown)->toContain('there is no rule SL10')
        ->and($unknown)->toContain('sloppy.rules.SL101.max_line is not an option of this rule (did you mean max_lines?)')
        ->and($unknown)->toContain('sloppy.score.bandz is not a setting')
        ->and($problems)->not->toContain('fial_on')
        ->and($problems)->toContain('sloppy.paths must be a list of strings')
        ->and($problems)->toContain('sloppy.min_confidence must be a whole number from 0 to 100')
        ->and($problems)->toContain('sloppy.rules.SL101.enabled must be true or false')
        ->and($problems)->toContain('sloppy.rules.SL101.severity must be one of')
        ->and($problems)->toContain('sloppy.rules.SL102 must be an array of options')
        ->and($problems)->toContain('sloppy.score.weights.high must be a number of 0 or more')
        ->and($problems)->toContain('sloppy.score.penalty_multiplier must be a number of 0 or more')
        ->and($problems)->toContain('sloppy.risk.reach_weight must be a number of 0 or more')
        ->and($problems)->toContain('sloppy.health.ttl must be a whole number of 0 or more')
        ->and(fn (): Sloppy => (new ConfigurationResolver)->resolve(new Sloppy($config), new ScanOptions))
        ->toThrow(InvalidArgumentException::class, 'The sloppy configuration is invalid');
});

it('lets a custom rule take whatever options it reads', function (): void {
    $config = Configuration::fromArray([
        'custom_rules' => [Heyosseus\Sloppy\Rules\Php\GodMethodRule::class],
        'rules' => ['SL101' => ['max_lines' => 10]],
    ], '/project');

    expect($config->problems)->toBe([])
        ->and($config->unknownSettings)->toBe([]);
});

it('warns about a key it does not know, and refuses a value it cannot use', function (): void {
    // A stale or misspelt key must not turn a configuration that worked
    // before an upgrade into a failed build; a value of the wrong type must,
    // or the run would analyse something the configuration did not ask for.
    $root = tempProject(['app/A.php' => "<?php\n\nclass A\n{\n}\n"]);
    $output = new RecordingRunnerOutput;

    $code = (new ScanRunner)->run(new Sloppy(Configuration::fromArray(['fial_on' => 'high'], $root)), new ScanOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and(implode('
', $output->messages()))->toContain('sloppy.fial_on is not a setting Sloppy knows (did you mean fail_on?). It is ignored.')
        ->and(fn (): Sloppy => (new ConfigurationResolver)->resolve(new Sloppy(Configuration::fromArray(['rules' => ['SL107' => ['enabled' => 'nah']]], $root)), new ScanOptions))
        ->toThrow(InvalidArgumentException::class);

    removeTree($root);
});
