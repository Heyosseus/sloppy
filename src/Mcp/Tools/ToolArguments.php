<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp\Tools;

/**
 * Typed reads over arguments that arrived as decoded JSON.
 *
 * The caller is a language model, so the arguments are whatever it decided to
 * send: a string where an array was documented, a numeric string for an
 * integer, a key that is not in the schema at all. None of that should be an
 * exception -- the tool takes what it can use and ignores the rest, because a
 * protocol error teaches the model nothing about the code it asked about.
 */
final readonly class ToolArguments
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values) {}

    public function string(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function int(string $key): ?int
    {
        $value = $this->values[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * A list of strings, accepting a bare string as a list of one.
     *
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->values[$key] ?? null;

        if (is_string($value)) {
            return trim($value) === '' ? [] : [trim($value)];
        }

        $strings = [];

        /** @var mixed $entry */
        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $strings[] = trim($entry);
            }
        }

        return $strings;
    }
}
