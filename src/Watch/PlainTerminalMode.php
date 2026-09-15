<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

/**
 * The terminal, left exactly as it was found.
 *
 * This is what runs where unbuffered input cannot be arranged: Windows, where
 * PHP has no way to put a console into raw mode, and anywhere the process is
 * not attached to a terminal at all. The dashboard still opens, still watches
 * and still redraws on every change -- it simply never asks for a key it would
 * not be given.
 */
final readonly class PlainTerminalMode implements TerminalMode
{
    public function enable(int $timeout): bool
    {
        return false;
    }

    public function restore(): void
    {
        // Nothing was changed, so there is nothing to put back.
    }
}
