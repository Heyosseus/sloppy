<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Evidence;

/**
 * How many errors a baseline silences, per source file.
 *
 * Deliberately shallow. A full NEON parser would be a dependency and a
 * maintenance burden for a signal that needs two fields, and the comparison
 * this feeds is a count per path -- not a structural diff -- so nothing else in
 * the file matters.
 *
 * Counting by the `count:` value rather than by blocks is the point: one entry
 * saying `count: 7` has silenced seven errors, and a change that bumps it to
 * fourteen has silenced seven more.
 */
final class BaselineEntries
{
    private function __construct() {}

    /**
     * @return array<string, int>
     */
    public static function for(string $filename, string $contents): array
    {
        return str_ends_with(mb_strtolower($filename), '.xml')
            ? self::fromXml($contents)
            : self::fromNeon($contents);
    }

    /**
     * PHPStan's `phpstan-baseline.neon`.
     *
     * @return array<string, int>
     */
    public static function fromNeon(string $contents): array
    {
        $counts = [];
        $pending = 1;

        foreach (self::lines($contents) as $line) {
            if (preg_match('/^\s*count:\s*(\d+)/', $line, $matches) === 1) {
                $pending = (int) $matches[1];

                continue;
            }

            if (preg_match('/^\s*path:\s*(.+?)\s*$/', $line, $matches) === 1) {
                $path = self::normalise(trim($matches[1], "'\""));
                $counts[$path] = ($counts[$path] ?? 0) + $pending;
                $pending = 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Psalm's `psalm-baseline.xml`.
     *
     * @return array<string, int>
     */
    public static function fromXml(string $contents): array
    {
        $counts = [];
        $path = null;

        foreach (self::lines($contents) as $line) {
            if (preg_match('/<file\s+src=["\'](.+?)["\']/', $line, $matches) === 1) {
                $path = self::normalise($matches[1]);
                $counts[$path] ??= 0;

                continue;
            }

            if ($path !== null && preg_match('/occurrences=["\'](\d+)["\']/', $line, $matches) === 1) {
                $counts[$path] += (int) $matches[1];
            }
        }

        ksort($counts);

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }

    /**
     * @return list<string>
     */
    private static function lines(string $contents): array
    {
        $lines = preg_split('/\R/', $contents);

        return $lines === false ? [] : $lines;
    }

    private static function normalise(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), './');
    }
}
