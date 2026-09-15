<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Watch\PlainTerminalMode;

it('never claims a keypress it cannot deliver', function (): void {
    expect((new PlainTerminalMode)->enable(250))->toBeFalse();
});

it('has nothing to put back, because it changed nothing', function (): void {
    $mode = new PlainTerminalMode;
    $mode->enable(250);

    expect(fn () => $mode->restore())->not->toThrow(Throwable::class);
});
