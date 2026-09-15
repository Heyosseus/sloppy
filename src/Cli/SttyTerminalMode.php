<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Closure;
use Heyosseus\Sloppy\Watch\TerminalMode;

/**
 * Unbuffered input, the POSIX way.
 *
 * `stty -icanon -echo` stops the terminal holding input until Enter and stops
 * it echoing keys into the middle of the frame. `min 0 time N` then makes a
 * read return after N tenths of a second with whatever arrived -- which is
 * also how the watch loop is paced, so there is no separate sleep anywhere.
 *
 * The exact settings in force are saved first and put back on the way out. A
 * command that exits leaving the shell with no echo has broken the terminal
 * for everything that runs after it.
 *
 * `stty` has to inherit this process's terminal, so it is run through the
 * shell rather than through {@see \Heyosseus\Sloppy\Integrations\Tooling\ToolRunner},
 * whose child processes get no TTY and would report the settings of nothing.
 */
final class SttyTerminalMode implements TerminalMode
{
    /** @var Closure(string): ?string */
    private readonly Closure $shell;

    private ?string $original = null;

    /**
     * @param  (callable(string): ?string)|null  $shell  How to run a command; the real shell when omitted.
     * @param  string  $separator  The platform's path separator, which is the cheapest way to ask whether this is Windows.
     */
    public function __construct(?callable $shell = null, private readonly string $separator = DIRECTORY_SEPARATOR)
    {
        $this->shell = $shell === null
            ? static fn (string $command): ?string => shell_exec($command) ?: null
            : $shell(...);
    }

    public function enable(int $timeout): bool
    {
        // Windows has no stty, and PHP has no other way to put a console into
        // raw mode, so there is nothing to attempt.
        if ($this->separator !== '/') {
            return false;
        }

        $original = ($this->shell)('stty -g');

        // No terminal is attached -- a pipe, a CI runner, a test suite -- so
        // there are no keypresses to be had and nothing to restore.
        if ($original === null || trim($original) === '') {
            return false;
        }

        $this->original = trim($original);

        // stty counts in tenths of a second, and `time 0` means "wait for a
        // key", which would stop the loop redrawing until one was pressed.
        ($this->shell)(sprintf('stty -icanon -echo min 0 time %d', max(1, (int) round($timeout / 100))));

        return true;
    }

    public function restore(): void
    {
        if ($this->original === null) {
            return;
        }

        ($this->shell)('stty '.$this->original);

        $this->original = null;
    }
}
