<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Support;

/**
 * Reads a `VALUE_IS_ARRAY` console option down to the non-empty, trimmed
 * strings it actually contains.
 *
 * The standalone binary reads options off Symfony's `InputInterface` and the
 * Artisan surface reads them off `Command::option()` -- two different APIs
 * that arrive at the same raw value, which is where this starts. Splitting
 * the class in two used to mean this filtering loop was typed out twice, and
 * `SL111` is right that two copies of a loop are one loop away from drifting.
 */
final class StringListOption
{
    private function __construct() {}

    /**
     * @return list<string>
     */
    public static function from(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        /** @var mixed $item */
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }
}
