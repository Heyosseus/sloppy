<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

/**
 * Whatever has to be done to a terminal before it will hand over a keypress.
 *
 * A terminal in its usual state buffers input until Enter and echoes it back,
 * both of which are wrong for a dashboard. Undoing that is entirely
 * platform-specific -- and on some platforms it cannot be done from PHP at all
 * -- so it lives behind this rather than inside the dashboard, which would
 * otherwise be untestable off a TTY and unusable on Windows.
 */
interface TerminalMode
{
    /**
     * Ask for unbuffered input, reporting whether the terminal agreed.
     *
     * False is not an error. It means this terminal will not deliver a
     * keypress, and the caller should stop asking for one.
     *
     * @param  int  $timeout  Milliseconds a single read may wait for input.
     */
    public function enable(int $timeout): bool;

    /**
     * Put the terminal back. A watch command that exits leaving the shell
     * without an echo is worse than one that never ran.
     */
    public function restore(): void;
}
