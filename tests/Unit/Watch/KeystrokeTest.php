<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Watch\KeyPress;
use Heyosseus\Sloppy\Watch\Keystroke;

it('reads the arrow keys', function (): void {
    expect(Keystroke::parse("\e[A")->press)->toBe(KeyPress::Up)
        ->and(Keystroke::parse("\e[B")->press)->toBe(KeyPress::Down);
});

it('reads enter as a request to open the selection', function (): void {
    expect(Keystroke::parse("\r")->press)->toBe(KeyPress::Open)
        ->and(Keystroke::parse("\n")->press)->toBe(KeyPress::Open)
        ->and(Keystroke::parse("\r\n")->press)->toBe(KeyPress::Open);
});

it('reads the letter keys', function (): void {
    expect(Keystroke::parse('r')->press)->toBe(KeyPress::Rescan)
        ->and(Keystroke::parse('q')->press)->toBe(KeyPress::Quit);
});

it('tolerates the newline a pipe adds', function (): void {
    expect(Keystroke::parse("q\n")->press)->toBe(KeyPress::Quit)
        ->and(Keystroke::parse("r\r\n")->press)->toBe(KeyPress::Rescan);
});

it('ignores anything it does not recognise', function (): void {
    expect(Keystroke::parse('')->press)->toBe(KeyPress::None)
        ->and(Keystroke::parse('z')->press)->toBe(KeyPress::None)
        ->and(Keystroke::parse("\e")->press)->toBe(KeyPress::None)
        ->and(Keystroke::parse("\e[C")->press)->toBe(KeyPress::None)
        ->and(Keystroke::parse('   ')->press)->toBe(KeyPress::None);
});

it('is case-insensitive about the letters', function (): void {
    expect(Keystroke::parse('Q')->press)->toBe(KeyPress::Quit)
        ->and(Keystroke::parse('R')->press)->toBe(KeyPress::Rescan);
});
