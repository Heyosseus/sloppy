<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\InterruptHandler;
use Heyosseus\Sloppy\Cli\TerminalDashboard;
use Heyosseus\Sloppy\Tests\Support\ScriptedTerminalMode;
use Heyosseus\Sloppy\Watch\KeyPress;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * A dashboard writing into memory and reading a scripted file, which is the
 * whole reason nothing here reaches for STDIN or STDOUT on its own.
 *
 * @return array{0: TerminalDashboard, 1: StreamOutput, 2: ScriptedTerminalMode}
 */
function dashboard(string $typed = '', bool $keypresses = true, int $width = 80): array
{
    $handle = fopen('php://memory', 'r+');
    $output = new StreamOutput($handle === false ? fopen('php://temp', 'r+') : $handle, StreamOutput::VERBOSITY_NORMAL, true);

    $path = tempProject([]).'/typed.txt';
    file_put_contents($path, $typed);

    $mode = new ScriptedTerminalMode($keypresses);

    return [new TerminalDashboard($output, new SplFileObject($path), $mode, $width, 0), $output, $mode];
}

function written(StreamOutput $output): string
{
    rewind($output->getStream());

    return (string) stream_get_contents($output->getStream());
}

it('reports the width it was given', function (): void {
    [$dashboard] = dashboard(width: 120);

    expect($dashboard->width())->toBe(120);
});

it('asks the terminal for keypresses when it opens, and gives them back when it closes', function (): void {
    [$dashboard, , $mode] = dashboard();
    $dashboard->open();

    expect($mode->enabled)->toBeTrue()
        ->and($dashboard->readsKeypresses())->toBeTrue()
        ->and($mode->restored)->toBeFalse();

    $dashboard->close();

    expect($mode->restored)->toBeTrue();
});

it('knows when the terminal will not give it keypresses', function (): void {
    [$dashboard] = dashboard(keypresses: false);
    $dashboard->open();

    expect($dashboard->readsKeypresses())->toBeFalse();
});

it('hides the cursor while it draws and shows it again afterwards', function (): void {
    [$dashboard, $output] = dashboard();
    $dashboard->open();
    $dashboard->close();

    // A blinking cursor parked in the middle of a redrawing frame is the
    // difference between a dashboard and a mess.
    expect(written($output))->toContain("\e[?25l")
        ->and(written($output))->toContain("\e[?25h");
});

it('draws the lines it is given', function (): void {
    [$dashboard, $output] = dashboard();
    $dashboard->draw(['first', 'second']);

    expect(written($output))->toContain('first')
        ->and(written($output))->toContain('second');
});

it('redraws over the previous frame instead of scrolling past it', function (): void {
    [$dashboard, $output] = dashboard();
    $dashboard->draw(['a', 'b', 'c']);
    $dashboard->draw(['a', 'b', 'c']);

    // Three lines up, so the second frame lands on top of the first.
    expect(written($output))->toContain("\e[3A");
});

it('wipes the rows a shorter frame leaves behind', function (): void {
    [$dashboard, $output] = dashboard();
    $dashboard->draw(['a', 'b', 'c', 'd']);
    $before = strlen(written($output));
    $dashboard->draw(['a']);

    // Four rows are still written -- one of content and three of nothing --
    // or the tail of the last frame stays on screen.
    expect(substr_count(substr(written($output), $before), "\e[2K"))->toBe(4);
});

it('reads a keypress', function (): void {
    [$dashboard] = dashboard('q');
    $dashboard->open();

    expect($dashboard->read()->press)->toBe(KeyPress::Quit);
});

it('reads an arrow key, escape sequence and all', function (): void {
    [$dashboard] = dashboard("\e[B");
    $dashboard->open();

    expect($dashboard->read()->press)->toBe(KeyPress::Down);
});

it('reports nothing once the input runs out', function (): void {
    [$dashboard] = dashboard('');
    $dashboard->open();

    expect($dashboard->read()->press)->toBe(KeyPress::None);
});

it('does not touch the input at all when keypresses are unavailable', function (): void {
    // On a terminal that cannot deliver a keypress, reading would block until
    // Enter -- and a frame that only redraws when you press a key is not a
    // watch. It waits out the interval and redraws instead.
    [$dashboard] = dashboard('q', keypresses: false);
    $dashboard->open();

    expect($dashboard->read()->press)->toBe(KeyPress::None);
});

it('starts a fresh frame when it takes the terminal back', function (): void {
    // Opening a finding hands the terminal to the editor, whose output lands
    // where the frame used to be. Redrawing over that would scroll up into it.
    [$dashboard, $output] = dashboard();
    $dashboard->draw(['a', 'b', 'c']);
    $dashboard->close();
    $dashboard->open();

    $before = strlen(written($output));
    $dashboard->draw(['a']);

    expect(substr(written($output), $before))->not->toContain("\e[3A");
});

it('gives the terminal back before an interrupt ends the process', function (): void {
    $handle = fopen('php://memory', 'r+');
    $output = new StreamOutput($handle === false ? fopen('php://temp', 'r+') : $handle, StreamOutput::VERBOSITY_NORMAL, true);
    $mode = new ScriptedTerminalMode(true);
    $exits = [];
    $interrupts = new InterruptHandler(static function (int $code) use (&$exits): void {
        $exits[] = $code;
    });

    $dashboard = new TerminalDashboard($output, new SplTempFileObject, $mode, 80, 0, true, $interrupts);
    $dashboard->open();

    // What Ctrl+C delivers -- SIGINT, or the Windows console event.
    $interrupts->interrupt(2);

    expect($mode->restored)->toBeTrue()
        ->and(strrpos(written($output), "\e[?25h"))->toBeGreaterThan(strrpos(written($output), "\e[?25l"))
        ->and($exits)->toBe([130]);
});

it('restores once, and not at all after it was closed the ordinary way', function (): void {
    $mode = new ScriptedTerminalMode(true);
    $restores = 0;
    $interrupts = new InterruptHandler(static function (int $code): void {});

    $interrupts->install(static function () use (&$restores): void {
        $restores++;
    });
    $interrupts->restore();
    $interrupts->restore();

    expect($restores)->toBe(1);

    $handle = fopen('php://memory', 'r+');
    $output = new StreamOutput($handle === false ? fopen('php://temp', 'r+') : $handle);
    $dashboard = new TerminalDashboard($output, new SplTempFileObject, $mode, 80, 0, true, $interrupts);
    $dashboard->open();
    $dashboard->close();
    $mode->restored = false;

    // A shutdown after a clean close has nothing left to restore.
    $interrupts->restore();

    expect($mode->restored)->toBeFalse();
});

it('puts back the signal handlers that were there before', function (): void {
    $before = pcntl_signal_get_handler(SIGINT);
    $interrupts = new InterruptHandler(static function (int $code): void {});

    $interrupts->install(static function (): void {});

    expect(pcntl_signal_get_handler(SIGINT))->not->toBe($before);

    $interrupts->uninstall();

    expect(pcntl_signal_get_handler(SIGINT))->toBe($before);
})->skip(! function_exists('pcntl_signal'), 'pcntl is not available on this platform.');
