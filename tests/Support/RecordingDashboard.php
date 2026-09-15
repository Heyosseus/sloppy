<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests\Support;

use Closure;
use Heyosseus\Sloppy\Watch\Dashboard;
use Heyosseus\Sloppy\Watch\KeyPress;
use Heyosseus\Sloppy\Watch\Keystroke;

/**
 * A dashboard with a script instead of a terminal.
 *
 * The loop is driven by whatever the keyboard says next, so a test drives it
 * by saying what the keyboard says next. Frames are kept rather than drawn,
 * which is how the whole of `watch` is asserted on without a TTY.
 *
 * The script running out means quit. A test that forgets to say so would
 * otherwise hang the suite rather than fail it.
 */
final class RecordingDashboard implements Dashboard
{
    /** @var list<list<string>> */
    public array $frames = [];

    public int $opened = 0;

    public int $closed = 0;

    /**
     * Run just before each read, so a test can change the project underneath
     * a running loop -- which is the one thing `watch` exists to notice.
     *
     * @var Closure(int): void|null
     */
    public ?Closure $beforeRead = null;

    /**
     * @param  list<KeyPress>  $script
     */
    public function __construct(
        private array $script = [],
        private readonly int $width = 80,
        private readonly bool $keypresses = true,
        private readonly bool $terminal = true,
    ) {}

    public function open(): void
    {
        $this->opened++;
    }

    public function close(): void
    {
        $this->closed++;
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
        $this->frames[] = $lines;
    }

    public function read(): Keystroke
    {
        if ($this->beforeRead instanceof Closure) {
            ($this->beforeRead)(count($this->frames));
        }

        return new Keystroke(array_shift($this->script) ?? KeyPress::Quit);
    }

    /**
     * The last frame drawn, flattened, which is what a test wants to read.
     */
    public function lastFrame(): string
    {
        return $this->frames === [] ? '' : implode("\n", $this->frames[count($this->frames) - 1]);
    }
}
