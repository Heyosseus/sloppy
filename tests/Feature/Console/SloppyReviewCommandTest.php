<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

it('presents a change as a risk-ordered reading order through sloppy:review', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', "<?php\n\nclass OrderController {}\n")->commit('clean');
    $repository->write('app/OrderController.php', SWALLOWING_CONTROLLER);

    bindRepository($repository);

    $this->artisan('sloppy:review')
        ->expectsOutputToContain('Sloppy review')
        ->expectsOutputToContain('Read in this order')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    $repository->remove();
});

it('shows the risk arithmetic under --explain-risk', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', "<?php\n\nclass OrderController {}\n")->commit('clean');
    $repository->write('app/OrderController.php', SWALLOWING_CONTROLLER);

    bindRepository($repository);

    $this->artisan('sloppy:review --explain-risk')
        ->expectsOutputToContain('confidence) x')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    $repository->remove();
});

it('emits review json without the console reading-order headings', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', "<?php\n\nclass OrderController {}\n")->commit('clean');
    $repository->write('app/OrderController.php', SWALLOWING_CONTROLLER);

    bindRepository($repository);

    $this->artisan('sloppy:review --format=json')
        ->expectsOutputToContain('"mode": "diff"')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    $repository->remove();
});

it('says so and passes when nothing new appeared', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/OrderController.php', SWALLOWING_CONTROLLER)->commit('sloppy already');

    bindRepository($repository);

    $this->artisan('sloppy:review')
        ->expectsOutputToContain('Nothing in this change needs reading.')
        ->assertExitCode(ExitCode::Success->value);

    $repository->remove();
});
