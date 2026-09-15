<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests\Support;

use Heyosseus\Sloppy\Watch\TerminalMode;

/**
 * A terminal that says whatever the test needs it to say.
 *
 * Whether single keypresses can be read is a property of the real terminal the
 * process is attached to, and a test suite has no terminal at all. This stands
 * in for one, so both halves of the dashboard -- the interactive one and the
 * monitor it degrades to -- can be tested off a TTY.
 */
final class ScriptedTerminalMode implements TerminalMode
{
    public bool $enabled = false;

    public bool $restored = false;

    public function __construct(private readonly bool $keypresses = true) {}

    public function enable(int $timeout): bool
    {
        $this->enabled = true;

        return $this->keypresses;
    }

    public function restore(): void
    {
        $this->restored = true;
    }
}
