<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Console\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * Bind the container to a throwaway git repository.
 *
 * @param  array<string, mixed>  $config
 */
function bindRepository(TempRepository $repository, array $config = []): void
{
    $merged = [
        'paths' => ['app'],
        'exclude' => ['vendor'],
        'fail_on' => 'high',
        ...$config,
    ];

    app()->instance(Configuration::class, Configuration::fromArray($merged, $repository->path));
    app()->instance(Sloppy::class, new Sloppy(Configuration::fromArray($merged, $repository->path)));
}

it('reports and fails on a finding the change introduced', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', "<?php\n\nclass OrderController {}\n")->commit('clean');
    $repository->write('app/OrderController.php', SWALLOWING_CONTROLLER);

    bindRepository($repository);

    $this->artisan('sloppy:diff')
        ->expectsOutputToContain('Sloppy Review')
        ->expectsOutputToContain('New findings: 1')
        ->expectsOutputToContain('✗ Failed')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    $repository->remove();
});

it('passes and says so when nothing new appeared', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', SWALLOWING_CONTROLLER)->commit('sloppy already');

    bindRepository($repository);

    $this->artisan('sloppy:diff')
        ->expectsOutputToContain('No analysable PHP changes')
        ->assertExitCode(ExitCode::Success->value);

    $repository->remove();
});

it('does not fail a build for debt it inherited', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', SWALLOWING_CONTROLLER)->commit('sloppy already');

    // Touch the file without adding or fixing anything a rule cares about.
    $repository->write('app/OrderController.php', str_replace(
        'class OrderController',
        "/**\n * Handles orders.\n */\nclass OrderController",
        SWALLOWING_CONTROLLER,
    ));

    bindRepository($repository);

    $this->artisan('sloppy:diff')
        ->expectsOutputToContain('No new findings.')
        ->expectsOutputToContain('✓ Passed')
        ->assertExitCode(ExitCode::Success->value);

    $repository->remove();
});

it('celebrates a fix as resolved', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', SWALLOWING_CONTROLLER)->commit('sloppy');
    $repository->write('app/OrderController.php', str_replace('return null;', 'throw $e;', SWALLOWING_CONTROLLER));

    bindRepository($repository);

    $this->artisan('sloppy:diff')
        ->expectsOutputToContain('Resolved: 1')
        ->assertExitCode(ExitCode::Success->value);

    $repository->remove();
});

it('compares against a named revision', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', "<?php\n\nclass OrderController {}\n")->commit('clean');
    $repository->write('app/OrderController.php', SWALLOWING_CONTROLLER)->commit('sloppy');

    bindRepository($repository);

    $this->artisan('sloppy:diff HEAD~1')
        ->expectsOutputToContain('New findings: 1')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    $repository->remove();
});

it('errors on a revision it cannot resolve', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php class A {}')->commit('first');

    bindRepository($repository);

    $this->artisan('sloppy:diff nope')
        ->expectsOutputToContain('could not be resolved')
        ->assertExitCode(ExitCode::Error->value);

    $repository->remove();
});

it('errors when the project is not a git repository', function (): void {
    $root = project(['app/A.php' => '<?php class A {}']);

    $this->artisan('sloppy:diff')
        ->expectsOutputToContain('is not a git repository')
        ->assertExitCode(ExitCode::Error->value);

    removeTree($root);
});

it('does nothing when disabled', function (): void {
    $root = project(['app/A.php' => '<?php class A {}'], ['enabled' => false]);

    $this->artisan('sloppy:diff')
        ->expectsOutputToContain('Sloppy is disabled')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('emits diff json', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', "<?php\n\nclass OrderController {}\n")->commit('clean');
    $repository->write('app/OrderController.php', SWALLOWING_CONTROLLER);

    bindRepository($repository);

    $this->artisan('sloppy:diff --format=json')
        ->expectsOutputToContain('"mode": "diff"')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    $repository->remove();
});
