<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SloppyApplication;
use Symfony\Component\Console\Tester\ApplicationTester;

function runSloppy(array $input): string
{
    $application = new SloppyApplication('test');
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);
    $tester->run($input);

    return $tester->getDisplay();
}

it('names the coverage report and its age in health output', function (): void {
    // Coverage goes stale, and a stale report silently reorders a review.
    // Sloppy does not guess at staleness -- it shows the file and the date and
    // lets the reader judge.
    $root = tempProject(['app/A.php' => '<?php class A {}', 'composer.json' => '{}']);
    mkdir($root.'/build/logs', 0o777, true);
    file_put_contents(
        $root.'/build/logs/clover.xml',
        '<?xml version="1.0"?><coverage><project><file name="app/A.php"><metrics statements="2" coveredstatements="1"/></file></project></coverage>',
    );

    expect(runSloppy(['command' => 'health', '--project' => $root]))
        ->toContain('build/logs/clover.xml');

    removeTree($root);
});

it('says when no coverage report was found', function (): void {
    // Health is the command people run to check their setup, so this is where
    // an absent report belongs -- discoverable without being a warning.
    $root = tempProject(['app/A.php' => '<?php class A {}', 'composer.json' => '{}']);

    expect(runSloppy(['command' => 'health', '--project' => $root]))
        ->toContain('No coverage report');

    removeTree($root);
});

it('offers --coverage on both diff surfaces', function (): void {
    // The two surfaces are thin shells over one runner, and the way that stops
    // being true is one of them quietly growing or losing an option.
    $application = new SloppyApplication('test');

    expect(array_keys($application->find('diff')->getDefinition()->getOptions()))->toContain('coverage')
        ->and(array_keys($application->find('review')->getDefinition()->getOptions()))->toContain('coverage');
});

it('offers --coverage on the Artisan surface too', function (): void {
    expect(file_get_contents(dirname(__DIR__, 3).'/src/Console/Commands/SloppyDiffCommand.php'))
        ->toContain('--coverage=')
        ->and(file_get_contents(dirname(__DIR__, 3).'/src/Console/Commands/SloppyReviewCommand.php'))
        ->toContain('--coverage=');
});

it('ships each setting where the thing that reads it lives', function (): void {
    // Coverage sits under `risk` because risk is the only thing it feeds, and
    // SL502's watched files sit in its own rule options beside SL501's
    // vocabulary. Both were briefly top-level keys, which pushed Configuration
    // past the god-class threshold its own SL102 reports.
    $config = require dirname(__DIR__, 3).'/config/sloppy.php';

    expect($config['risk'])->toHaveKey('coverage')
        ->and($config['risk']['coverage'])->toBeNull()
        ->and($config['risk']['exposure_weight'])->toBe(0.5)
        ->and($config['rules']['SL502']['files'])->toBe(['phpstan-baseline.neon', 'psalm-baseline.xml'])
        ->and($config)->not->toHaveKey('coverage')
        ->and($config)->not->toHaveKey('baselines');
});
