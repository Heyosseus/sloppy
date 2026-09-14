<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Baseline\Baseline;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\BaselineFilter;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

/**
 * @param  array<string, mixed>  $config
 * @return array{0: Sloppy, 1: string}
 */
function filteredProject(array $config = []): array
{
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Bad.php' => godMethodSource(),
    ]);

    return [new Sloppy(Configuration::fromArray([...['paths' => ['src']], ...$config], $root)), $root];
}

it('leaves the result alone when there is no baseline', function (): void {
    [$sloppy, $root] = filteredProject();
    $output = new RecordingRunnerOutput;
    $result = $sloppy->analyze();

    $filtered = (new BaselineFilter)->apply($sloppy, $result, $output);

    expect($filtered)->toBe($result)
        ->and($output->messages())->toBe([]);

    removeTree($root);
});

it('hides what the baseline accepts, and says how many', function (): void {
    [$sloppy, $root] = filteredProject();
    $result = $sloppy->analyze();
    $output = new RecordingRunnerOutput;

    $sloppy->baselines()->save(Baseline::fromFindings($result->findings), $sloppy->configuration->baselinePath());

    $filtered = (new BaselineFilter)->apply($sloppy, $result, $output);

    expect($filtered->count())->toBe(0)
        ->and($filtered->score->value)->toBe(100)
        ->and($output->messages())->toBe(['notice: 1 existing finding(s) hidden by .sloppy-baseline.json.']);

    removeTree($root);
});

it('reports everything when the baseline is turned off', function (): void {
    [$sloppy, $root] = filteredProject();
    $result = $sloppy->analyze();
    $output = new RecordingRunnerOutput;

    $sloppy->baselines()->save(Baseline::fromFindings($result->findings), $sloppy->configuration->baselinePath());

    $filtered = (new BaselineFilter)->apply($sloppy, $result, $output, enabled: false);

    expect($filtered->count())->toBe(1)
        ->and($output->messages())->toBe([]);

    removeTree($root);
});

it('says nothing when a baseline exists but hides nothing', function (): void {
    [$sloppy, $root] = filteredProject();
    $output = new RecordingRunnerOutput;

    $sloppy->baselines()->save(Baseline::fromFindings([]), $sloppy->configuration->baselinePath());

    $filtered = (new BaselineFilter)->apply($sloppy, $sloppy->analyze(), $output);

    expect($filtered->count())->toBe(1)
        ->and($output->messages())->toBe([]);

    removeTree($root);
});
