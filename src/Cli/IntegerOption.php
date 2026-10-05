<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use InvalidArgumentException;

/**
 * A whole-number command-line option, read the same way on both surfaces.
 *
 * `is_numeric()` is not the test: it accepts `1.5`, `1e3` and ` 7`, which
 * `(int)` then quietly turns into a different number than the one typed.
 */
final readonly class IntegerOption
{
    /**
     * @throws InvalidArgumentException When the value is not a whole number.
     */
    public static function parse(string $name, ?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^[+-]?\d+$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('--%s must be a number without a fraction, got "%s".', $name, $value));
        }

        return (int) $value;
    }
}
