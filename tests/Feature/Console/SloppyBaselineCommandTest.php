<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Baseline\BaselineManager;
use Heyosseus\Sloppy\Runner\ExitCode;

it('records the current findings and then keeps them quiet', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['fail_on' => 'high']);

    // Before: the finding fails the build.
    $this->artisan('sloppy')->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    $this->artisan('sloppy:baseline')
        ->expectsOutputToContain('Baselined 1 finding(s)')
        ->assertExitCode(ExitCode::Success->value);

    expect(is_file($root.'/.sloppy-baseline.json'))->toBeTrue();

    // After: the same debt is accepted, so the build passes.
    $this->artisan('sloppy')
        ->expectsOutputToContain('1 existing finding(s) hidden')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('still fails when new debt appears on top of a baseline', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['fail_on' => 'high']);

    $this->artisan('sloppy:baseline')->assertExitCode(ExitCode::Success->value);

    // A second swallowed exception, in a file the baseline never saw.
    file_put_contents($root.'/app/InvoiceController.php', str_replace(
        'OrderController',
        'InvoiceController',
        SWALLOWING_CONTROLLER,
    ));

    $this->artisan('sloppy')
        ->expectsOutputToContain('SL107')
        ->expectsOutputToContain('✗ Failed')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    removeTree($root);
});

it('can be told to ignore the baseline', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['fail_on' => 'high']);

    $this->artisan('sloppy:baseline')->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy')->assertExitCode(ExitCode::Success->value);
    $this->artisan('sloppy --no-baseline')->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    removeTree($root);
});

it('refuses to overwrite an existing baseline without --force', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER]);

    $this->artisan('sloppy:baseline')->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy:baseline')
        ->expectsOutputToContain('already exists')
        ->assertExitCode(ExitCode::Error->value);

    $this->artisan('sloppy:baseline --force')
        ->expectsOutputToContain('Previous baseline held 1 finding(s)')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('reports how the baseline changed when replaced', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER]);

    $this->artisan('sloppy:baseline')->assertExitCode(ExitCode::Success->value);

    // Fix the finding, then re-baseline: the count should drop.
    file_put_contents($root.'/app/OrderController.php', str_replace(
        'return null;',
        'throw $e;',
        SWALLOWING_CONTROLLER,
    ));

    $this->artisan('sloppy:baseline --force')
        ->expectsOutputToContain('Baselined 0 finding(s)')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('records the score it was taken at', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER]);

    $this->artisan('sloppy:baseline')->assertExitCode(ExitCode::Success->value);

    $baseline = (new BaselineManager)->load($root.'/.sloppy-baseline.json');

    expect($baseline?->score)->toBeInt()
        ->and($baseline?->score)->toBeLessThan(100)
        ->and($baseline?->generatedAt)->not->toBe('');

    removeTree($root);
});

it('baselines only the rules it was told to', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['fail_on' => 'high']);

    $this->artisan('sloppy:baseline --rule=SL101')
        ->expectsOutputToContain('Baselined 0 finding(s)')
        ->assertExitCode(ExitCode::Success->value);

    // SL107 was never baselined, so it still fails.
    $this->artisan('sloppy')->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    removeTree($root);
});

it('reports a corrupt baseline rather than silently ignoring it', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER]);
    file_put_contents($root.'/.sloppy-baseline.json', '{not json');

    $this->artisan('sloppy')
        ->expectsOutputToContain('is not valid JSON')
        ->assertExitCode(ExitCode::Error->value);

    removeTree($root);
});
