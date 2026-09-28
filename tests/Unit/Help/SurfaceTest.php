<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Help\Surface;
use Heyosseus\Sloppy\Support\Terminal;
use Symfony\Component\Console\Input\ArrayInput;

it('spells a command the way each surface types it', function (): void {
    expect(Surface::Standalone->command('fix'))->toBe('vendor/bin/sloppy fix')
        ->and(Surface::Artisan->command('fix'))->toBe('php artisan sloppy:fix')
        ->and(Surface::Artisan->command('scan', '--all'))->toBe('php artisan sloppy --all')
        ->and(Surface::Standalone->command('scan', '--all'))->toBe('vendor/bin/sloppy scan --all');
});

it('falls back to the standalone name for a command it does not know', function (): void {
    expect(Surface::Artisan->command('nope'))->toBe('vendor/bin/sloppy nope');
});

it('never asks a question of input that is not interactive', function (): void {
    $input = new ArrayInput([]);
    $input->setInteractive(false);

    expect(Terminal::canAsk($input))->toBeFalse();
});
