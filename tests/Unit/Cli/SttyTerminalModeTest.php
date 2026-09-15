<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SttyTerminalMode;

/**
 * A shell that answers whatever the test wants and remembers what it was asked.
 *
 * @param  list<string>  $commands
 * @return callable(string): ?string
 */
function recordingShell(array &$commands, ?string $answer = 'saved-settings'): callable
{
    return static function (string $command) use (&$commands, $answer): ?string {
        $commands[] = $command;

        return $answer;
    };
}

it('will not try on a platform that has no stty', function (): void {
    $commands = [];
    $mode = new SttyTerminalMode(recordingShell($commands), '\\');

    expect($mode->enable(250))->toBeFalse()
        ->and($commands)->toBe([]);
});

it('saves the terminal settings before changing them', function (): void {
    $commands = [];
    $mode = new SttyTerminalMode(recordingShell($commands), '/');

    expect($mode->enable(250))->toBeTrue()
        ->and($commands[0])->toBe('stty -g');
});

it('asks for unbuffered input with no echo', function (): void {
    $commands = [];
    (new SttyTerminalMode(recordingShell($commands), '/'))->enable(250);

    expect($commands[1])->toContain('-icanon')
        ->and($commands[1])->toContain('-echo')
        ->and($commands[1])->toContain('min 0');
});

it('turns the poll interval into the tenths of a second stty speaks', function (): void {
    $commands = [];
    (new SttyTerminalMode(recordingShell($commands), '/'))->enable(250);
    expect($commands[1])->toContain('time 3');

    $faster = [];
    (new SttyTerminalMode(recordingShell($faster), '/'))->enable(40);

    // stty cannot wait for less than a tenth of a second, and a zero there
    // means "block until a key arrives", which would stop the loop dead.
    expect($faster[1])->toContain('time 1');
});

it('gives up when the terminal will not report its settings', function (): void {
    $commands = [];
    $mode = new SttyTerminalMode(recordingShell($commands, null), '/');

    expect($mode->enable(250))->toBeFalse()
        ->and($commands)->toBe(['stty -g']);
});

it('puts the saved settings back', function (): void {
    $commands = [];
    $mode = new SttyTerminalMode(recordingShell($commands, ' raw-settings '), '/');
    $mode->enable(250);
    $mode->restore();

    expect($commands[2])->toBe('stty raw-settings');
});

it('restores nothing when it never changed anything', function (): void {
    $commands = [];
    (new SttyTerminalMode(recordingShell($commands), '\\'))->restore();

    expect($commands)->toBe([]);
});

it('runs a real shell when it is not given one', function (): void {
    // No terminal is attached to a test run, so stty has nothing to report and
    // the mode declines -- which is the same answer it must give on a CI
    // runner, in a pipe, or anywhere else there is no TTY.
    expect((new SttyTerminalMode(separator: '/'))->enable(250))->toBeFalse();
});
