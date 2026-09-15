<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

/**
 * One piece of input from the person watching, read as an intent.
 *
 * A terminal in raw mode delivers single bytes, and an arrow key as a
 * three-byte escape sequence. A trailing newline is tolerated so that the same
 * parser reads input that arrived through a pipe, where the shell adds one.
 */
final readonly class Keystroke
{
    public function __construct(public KeyPress $press) {}

    public static function parse(string $input): self
    {
        $arrow = self::arrow($input);

        if ($arrow instanceof KeyPress) {
            return new self($arrow);
        }

        $trimmed = trim($input, " \t\r\n");

        if ($trimmed === '') {
            // A bare Enter opens whatever is selected; whitespace, or nothing
            // at all, means the loop simply had nothing waiting for it.
            return new self(self::hasNewline($input) ? KeyPress::Open : KeyPress::None);
        }

        return new self(match (strtolower($trimmed)) {
            'q' => KeyPress::Quit,
            'r' => KeyPress::Rescan,
            default => KeyPress::None,
        });
    }

    private static function arrow(string $input): ?KeyPress
    {
        return match ($input) {
            "\e[A" => KeyPress::Up,
            "\e[B" => KeyPress::Down,
            default => null,
        };
    }

    private static function hasNewline(string $input): bool
    {
        return str_contains($input, "\n") || str_contains($input, "\r");
    }
}
