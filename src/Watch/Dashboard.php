<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

use Heyosseus\Sloppy\Runner\RunnerOutput;

/**
 * A screen that redraws, and the keyboard in front of it.
 *
 * {@see RunnerOutput} is the port every other command writes through, and it
 * is deliberately not this. It knows how to emit a finished report and has no
 * concept of a frame or of input, which is right for the nine commands that
 * print once and exit. Widening it so that one command can draw would hand
 * every runner and both adapters methods they will never call.
 *
 * So `watch` takes this alongside it: `RunnerOutput` still carries the errors
 * that happen before the loop starts, and everything inside the loop goes
 * through here.
 */
interface Dashboard
{
    /**
     * Take the terminal: hide the cursor, and ask for single keypresses if
     * this terminal has them to give.
     */
    public function open(): void;

    /**
     * Give the terminal back exactly as it was found, whatever happened.
     */
    public function close(): void;

    /**
     * Whether there is a terminal on the other end at all.
     *
     * Redirected into a file or a pipe, a redrawing frame is meaningless and
     * there is no key to press to stop it -- so the loop would never end. The
     * runner refuses rather than hanging.
     */
    public function isTerminal(): bool;

    /**
     * How wide the frame may be.
     */
    public function width(): int;

    /**
     * Whether a keypress can be read without waiting for Enter. False turns
     * the dashboard into a monitor, and the frame says so rather than
     * offering keys that will not work.
     */
    public function readsKeypresses(): bool;

    /**
     * Put a frame on screen, over the previous one.
     *
     * @param  list<string>  $lines
     */
    public function draw(array $lines): void;

    /**
     * Wait up to one poll interval for input.
     *
     * This is also what paces the loop: there is no separate sleep, because a
     * read with a timeout is the same wait, and one that ends early when
     * somebody presses a key.
     */
    public function read(): Keystroke;
}
