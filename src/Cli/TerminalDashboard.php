<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Watch\Dashboard;
use Heyosseus\Sloppy\Watch\KeyPress;
use Heyosseus\Sloppy\Watch\Keystroke;
use Heyosseus\Sloppy\Watch\PlainTerminalMode;
use Heyosseus\Sloppy\Watch\TerminalMode;
use SplFileObject;
use Symfony\Component\Console\Cursor;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The dashboard on a real terminal.
 *
 * Every collaborator arrives through the constructor -- the output, the input,
 * and the thing that knows how to unbuffer a terminal -- so the class that
 * talks to the screen is as testable as the ones that do not. Nothing here
 * reaches for `STDIN`, `STDOUT` or the environment.
 *
 * Input is an {@see SplFileObject} for the same reason {@see \Heyosseus\Sloppy\Mcp\StdioTransport}
 * uses one: a test can serve a file and drive the loop with it, which is the
 * only way to test a loop over standard input.
 */
final class TerminalDashboard implements Dashboard
{
    private readonly Cursor $cursor;

    /** How many rows the previous frame occupied. */
    private int $drawn = 0;

    private bool $keypresses = false;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly SplFileObject $input,
        private readonly TerminalMode $mode = new PlainTerminalMode,
        private readonly int $width = 80,
        private readonly int $interval = 250,
        private readonly bool $terminal = true,
    ) {
        $this->cursor = new Cursor($this->output);
    }

    public function open(): void
    {
        $this->keypresses = $this->mode->enable($this->interval);

        // Whatever was on screen belongs to whoever had the terminal before
        // -- the shell, or an editor a finding was just opened in. The next
        // frame starts below it rather than scrolling up over it.
        $this->drawn = 0;

        $this->cursor->hide();
    }

    public function close(): void
    {
        $this->cursor->show();

        $this->mode->restore();
    }

    public function isTerminal(): bool
    {
        return $this->terminal;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function readsKeypresses(): bool
    {
        return $this->keypresses;
    }

    public function draw(array $lines): void
    {
        if ($this->drawn > 0) {
            $this->cursor->moveUp($this->drawn);
        }

        foreach ($lines as $line) {
            $this->cursor->clearLine();
            $this->output->writeln($line);
        }

        // A frame shorter than the one before it would otherwise leave that
        // frame's tail on screen underneath it.
        for ($row = count($lines); $row < $this->drawn; $row++) {
            $this->cursor->clearLine();
            $this->output->writeln('');
        }

        $this->drawn = max(count($lines), $this->drawn);
    }

    public function read(): Keystroke
    {
        if (! $this->keypresses) {
            // Reading here would block until Enter, and a frame that redraws
            // only when a key is pressed is not watching anything.
            usleep($this->interval * 1000);

            return new Keystroke(KeyPress::None);
        }

        // The terminal itself enforces the timeout: `enable()` asked it to
        // return whatever has arrived after one interval, so this read is the
        // loop's pacing as well as its input.
        $typed = $this->input->fread(8);

        return Keystroke::parse($typed === false ? '' : $typed);
    }
}
