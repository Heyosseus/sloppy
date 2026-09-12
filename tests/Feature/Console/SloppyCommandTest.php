<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Illuminate\Contracts\Config\Repository;

/**
 * Point the package at a throwaway project and re-bind the container so the
 * command under test analyses it.
 *
 * @param  array<string, string>  $files  Relative path => contents.
 * @param  array<string, mixed>  $config
 */
function project(array $files, array $config = []): string
{
    $root = tempProject($files);

    /** @var Repository $repository */
    $repository = app(Repository::class);

    /** @var array<string, mixed> $current */
    $current = $repository->get('sloppy', []);

    $merged = [...$current, ...$config];
    $repository->set('sloppy', $merged);

    app()->instance(Configuration::class, Configuration::fromArray($merged, $root));
    app()->instance(Sloppy::class, new Sloppy(Configuration::fromArray($merged, $root)));

    return $root;
}

const SWALLOWING_CONTROLLER = <<<'PHP'
<?php

namespace App\Http\Controllers;

class OrderController
{
    public function show(int $id)
    {
        try {
            return $this->records->find($id);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
PHP;

const CLEAN_ACTION = <<<'PHP'
<?php

namespace App\Actions;

final class ShowOrder
{
    public function handle(int $id): mixed
    {
        return $this->records->findOrFail($id);
    }
}
PHP;

it('passes on clean code', function (): void {
    $root = project(['app/ShowOrder.php' => CLEAN_ACTION]);

    $this->artisan('sloppy')
        ->expectsOutputToContain('100/100')
        ->expectsOutputToContain('Nothing flagged.')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('fails when a finding meets the threshold', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['fail_on' => 'high']);

    // One substring per output line: Laravel matches each expectation against
    // a single write, so two phrases from the same line cannot both be asserted.
    $this->artisan('sloppy')
        ->expectsOutputToContain('SL107')
        ->expectsOutputToContain('app/OrderController.php')
        ->expectsOutputToContain('✗ Failed')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    $this->artisan('sloppy')
        ->expectsOutputToContain('Swallowed Exception')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    removeTree($root);
});

it('passes when the threshold is above what was found', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['fail_on' => 'critical']);

    $this->artisan('sloppy')
        ->expectsOutputToContain('SL107')
        ->expectsOutputToContain('✓ Passed')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('never fails on findings when the threshold is disabled', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['fail_on' => null]);

    $this->artisan('sloppy')->assertExitCode(ExitCode::Success->value);
    $this->artisan('sloppy --fail-on=never')->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('emits machine-readable json', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['fail_on' => 'high']);

    $this->artisan('sloppy --format=json')->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    removeTree($root);
});

it('rejects an unknown format with a runtime error', function (): void {
    $root = project(['app/ShowOrder.php' => CLEAN_ACTION]);

    $this->artisan('sloppy --format=xml')
        ->expectsOutputToContain('Unknown --format')
        ->assertExitCode(ExitCode::Error->value);

    removeTree($root);
});

it('rejects an unknown severity with a runtime error', function (): void {
    $root = project(['app/ShowOrder.php' => CLEAN_ACTION]);

    $this->artisan('sloppy --fail-on=urgent')
        ->expectsOutputToContain('Unknown severity')
        ->assertExitCode(ExitCode::Error->value);

    removeTree($root);
});

it('rejects a non-numeric confidence floor', function (): void {
    $root = project(['app/ShowOrder.php' => CLEAN_ACTION]);

    $this->artisan('sloppy --min-confidence=high')
        ->expectsOutputToContain('--min-confidence must be a number')
        ->assertExitCode(ExitCode::Error->value);

    removeTree($root);
});

it('restricts the run to chosen rules', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['fail_on' => 'high']);

    // SL101 does not fire on this file, so only-SL101 finds nothing.
    $this->artisan('sloppy --rule=SL101')
        ->expectsOutputToContain('Nothing flagged.')
        ->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy --rule=SL107')
        ->expectsOutputToContain('SL107')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    removeTree($root);
});

it('errors when no rules are left enabled', function (): void {
    $root = project(['app/ShowOrder.php' => CLEAN_ACTION]);

    $this->artisan('sloppy --rule=NOPE')
        ->expectsOutputToContain('No rules are enabled')
        ->assertExitCode(ExitCode::Error->value);

    removeTree($root);
});

it('drops findings below the confidence floor', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['fail_on' => 'high']);

    $this->artisan('sloppy --min-confidence=99')
        ->expectsOutputToContain('Nothing flagged.')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('analyses an overridden path', function (): void {
    $root = project([
        'app/ShowOrder.php' => CLEAN_ACTION,
        'legacy/OrderController.php' => SWALLOWING_CONTROLLER,
    ], ['fail_on' => 'high']);

    $this->artisan('sloppy')->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy --path=legacy')
        ->expectsOutputToContain('SL107')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    removeTree($root);
});

it('warns and succeeds when there is nothing to analyse', function (): void {
    $root = project([]);

    $this->artisan('sloppy')
        ->expectsOutputToContain('No PHP files found')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('does nothing when disabled', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['enabled' => false, 'fail_on' => 'high']);

    $this->artisan('sloppy')
        ->expectsOutputToContain('Sloppy is disabled')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('includes the why with --explain', function (): void {
    $root = project(['app/OrderController.php' => SWALLOWING_CONTROLLER], ['fail_on' => 'never']);

    // A short phrase, because the explanation is wrapped and a longer one
    // could straddle two lines.
    $this->artisan('sloppy --explain')
        ->expectsOutputToContain('observable')
        ->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy')
        ->doesntExpectOutputToContain('observable')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('reports files it could not parse', function (): void {
    $root = project([
        'app/Broken.php' => '<?php class Broken { public function',
        'app/ShowOrder.php' => CLEAN_ACTION,
    ]);

    $this->artisan('sloppy')
        ->expectsOutputToContain('Could not be analysed')
        ->expectsOutputToContain('app/Broken.php')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});
