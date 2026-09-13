<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use InvalidArgumentException;

/**
 * The report formats a command can produce.
 *
 * An enum rather than a string because the value decides whether output may be
 * treated as console markup, and a typo in that decision corrupts a report
 * instead of failing.
 */
enum OutputFormat: string
{
    case Console = 'console';
    case Json = 'json';
    case Sarif = 'sarif';
    case Markdown = 'markdown';
    case Github = 'github';

    public static function parse(string $value): self
    {
        $normalised = mb_strtolower(trim($value));
        $format = self::tryFrom($normalised);

        if (! $format instanceof self) {
            throw new InvalidArgumentException(sprintf(
                'Unknown --format [%s]. Expected %s.',
                $normalised,
                implode(' or ', array_map(static fn (self $case): string => $case->value, self::cases())),
            ));
        }

        return $format;
    }

    /**
     * Whether the report must be written raw, because it is parsed rather than
     * read.
     *
     * Markdown is included: it is written for a pull-request comment body, and
     * console markup or a progress bar mixed into it would be published
     * verbatim into that comment.
     */
    public function isMachineReadable(): bool
    {
        return $this !== self::Console;
    }

    /**
     * Whether this format expects to be redirected to a file rather than read
     * in a terminal.
     *
     * `github` is the exception among the machine-readable formats: its
     * workflow commands are meant to be *seen* by the Actions runner on
     * standard output, so telling a user to redirect it would break it.
     */
    public function expectsRedirection(): bool
    {
        return $this === self::Json || $this === self::Sarif;
    }
}
