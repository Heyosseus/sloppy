<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

/**
 * What the person at the keyboard asked the dashboard to do.
 *
 * The intent is named rather than the key, because the same intent arrives as
 * a different byte sequence depending on the terminal -- and, on Windows, only
 * after Enter.
 */
enum KeyPress
{
    /** Move the selection towards the top of the list. */
    case Up;

    /** Move the selection towards the bottom of the list. */
    case Down;

    /** Open the selected finding in the editor. */
    case Open;

    /** Throw away the cached parses and analyse from disk. */
    case Rescan;

    /** Leave, restoring the terminal. */
    case Quit;

    /** Nothing was pressed, or nothing that means anything here. */
    case None;
}
