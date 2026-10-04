<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * A name pattern where `*` stands for any run of characters, backslashes
 * included, so `*Http\Controllers*` means "contains `Http\Controllers`".
 * Nothing else is special: a backslash is a namespace separator, never an
 * escape.
 */
final readonly class Glob
{
    private string $expression;

    public function __construct(public string $pattern)
    {
        $parts = array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            explode('*', $pattern),
        );

        $this->expression = '/^'.implode('.*', $parts).'$/s';
    }

    public function matches(string $subject): bool
    {
        return preg_match($this->expression, $subject) === 1;
    }
}
