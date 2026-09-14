<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\HealthOptions;
use Heyosseus\Sloppy\Runner\HealthRunner;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

/**
 * @param  array<string, mixed>  $config
 * @return array{0: Sloppy, 1: string}
 */
function healthProject(array $config = []): array
{
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Bad.php' => godMethodSource(),
    ]);

    return [new Sloppy(Configuration::fromArray([...['paths' => ['src']], ...$config], $root)), $root];
}

it('says nothing and succeeds when sloppy is disabled', function (): void {
    [$sloppy, $root] = healthProject(['enabled' => false]);
    $output = new RecordingRunnerOutput;

    expect((new HealthRunner)->run($sloppy, new HealthOptions, $output))->toBe(ExitCode::Success)
        ->and($output->messages())->toBe(['info: Sloppy is disabled (sloppy.enabled is false).']);

    removeTree($root);
});

it('reports a bad option rather than analysing', function (): void {
    [$sloppy, $root] = healthProject();
    $output = new RecordingRunnerOutput;

    expect((new HealthRunner)->run($sloppy, new HealthOptions(minConfidence: 200), $output))->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toContain('--min-confidence must be a number between 0 and 100.');

    removeTree($root);
});

it('prints the score, the categories and what to read first', function (): void {
    [$sloppy, $root] = healthProject();
    $output = new RecordingRunnerOutput;

    $code = (new HealthRunner)->run($sloppy, new HealthOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->reports()[0][0])->toBe('console')
        ->and($output->reportBody())->toContain('/100')
        ->and($output->reportBody())->toContain('complexity')
        ->and($output->reportBody())->toContain('Read first:')
        ->and($output->reportBody())->toContain('SL101 God Method');

    removeTree($root);
});

it('emits the same snapshot as JSON', function (): void {
    [$sloppy, $root] = healthProject();
    $output = new RecordingRunnerOutput;

    $code = (new HealthRunner)->run($sloppy, new HealthOptions(json: true), $output);

    /** @var array{schema: int, tool: string, score: int, top: list<array<string, mixed>>} $decoded */
    $decoded = json_decode($output->reportBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->reports()[0][0])->toBe('json')
        ->and($decoded['schema'])->toBe(1)
        ->and($decoded['tool'])->toBe('sloppy')
        ->and($decoded['score'])->toBeLessThan(100)
        ->and($decoded['top'])->toHaveCount(1);

    removeTree($root);
});

it('says nothing is flagged for a clean project, without a read-first list', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Fine.php' => "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n",
    ]);

    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['src']], $root));

    (new HealthRunner)->run($sloppy, new HealthOptions, $output);

    expect($output->reportBody())->toContain('100/100')
        ->and($output->reportBody())->not->toContain('Read first:');

    removeTree($root);
});

it('re-analyses when a different number of ranked findings is asked for', function (): void {
    [$sloppy, $root] = healthProject();
    $output = new RecordingRunnerOutput;

    (new HealthRunner)->run($sloppy, new HealthOptions(json: true, top: 1), $output);

    /** @var array{top: list<array<string, mixed>>} $decoded */
    $decoded = json_decode($output->reportBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['top'])->toHaveCount(1)
        ->and(is_file($root.'/.sloppy-health.json'))->toBeTrue();

    removeTree($root);
});

it('reads the cache on a second run and refreshes it when asked', function (): void {
    [$sloppy, $root] = healthProject();

    (new HealthRunner)->run($sloppy, new HealthOptions, new RecordingRunnerOutput);

    $written = (int) filemtime($root.'/.sloppy-health.json');

    $second = new RecordingRunnerOutput;
    (new HealthRunner)->run($sloppy, new HealthOptions, $second);

    expect($second->reportBody())->toContain('/100')
        ->and((int) filemtime($root.'/.sloppy-health.json'))->toBe($written);

    $fresh = new RecordingRunnerOutput;
    expect((new HealthRunner)->run($sloppy, new HealthOptions(fresh: true), $fresh))->toBe(ExitCode::Success);

    removeTree($root);
});

it('renders a snapshot despite a file it could not parse, and an uncacheable path', function (): void {
    $root = tempProject(['composer.json' => '{}']);
    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['src'], 'health' => ['cache' => 'nope/health.json']], $root));

    mkdir($root.'/src');
    file_put_contents($root.'/src/Broken.php', "<?php\n\nclass Broken { function ( }\n");

    $output = new RecordingRunnerOutput;

    // A parse error does not stop a run -- it is reported per file -- so the
    // snapshot still renders, and a cache it cannot write does not break it.
    expect((new HealthRunner)->run($sloppy, new HealthOptions, $output))->toBe(ExitCode::Success)
        ->and($output->reportBody())->toContain('/100')
        ->and(is_file($root.'/nope/health.json'))->toBeFalse();

    removeTree($root);
});

it('surfaces a failure to build the run as an error', function (): void {
    [$sloppy, $root] = healthProject(['custom_rules' => ['App\\NotARule']]);
    $output = new RecordingRunnerOutput;

    expect((new HealthRunner)->run($sloppy, new HealthOptions, $output))->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toContain('must implement');

    removeTree($root);
});
