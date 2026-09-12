<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Configuration;

/**
 * Typed reads over a raw configuration array.
 *
 * Split out of {@see Configuration} so that class stays about what each
 * setting means; this one is only about safely getting a shaped value out of
 * an array that arrived as `mixed`.
 */
final readonly class ConfigReader
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values) {}

    public function bool(string $key, bool $default): bool
    {
        $value = $this->values[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    public function string(string $key, string $default): string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $values = [];

        /** @var mixed $value */
        foreach ($this->arrayValue($key) as $value) {
            if (is_string($value) && trim($value) !== '') {
                $values[] = trim($value);
            }
        }

        return $values;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function arrayValue(string $key): array
    {
        /** @var mixed $value */
        $value = $this->values[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
