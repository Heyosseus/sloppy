<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Watch;

/**
 * What the watched files looked like at one moment.
 *
 * `sloppy watch` polls rather than subscribing to filesystem events: `inotify`
 * and `FSEvents` need extensions this package does not require and cannot
 * assume, and the file list is already enumerated on every run. A few hundred
 * `stat` calls four times a second is cheap, and it behaves the same on every
 * platform.
 *
 * A file is "the same" when its modification time and its length are both
 * unchanged. That is the standard trade: `filemtime()` has one-second
 * resolution in PHP, so an edit that lands inside the same second *and* leaves
 * the length identical is invisible until the next change. Almost every real
 * edit moves the length; `r` forces a rescan for the ones that do not.
 */
final readonly class TreeState
{
    /**
     * @param  array<string, string>  $stamps  Relative path => stamp, empty for a file that is not there.
     */
    public function __construct(public array $stamps = []) {}

    /**
     * Stamp every file in a project's file map.
     *
     * @param  array<string, string>  $fileMap  Relative path => absolute path.
     */
    public static function of(array $fileMap): self
    {
        // PHP caches stat results per request, and a watch loop is one very
        // long request. Without this every poll after the first would read the
        // first poll's answer and the dashboard would never update.
        clearstatcache();

        $stamps = [];

        foreach ($fileMap as $relative => $absolute) {
            $stamps[$relative] = self::stamp($absolute);
        }

        return new self($stamps);
    }

    /**
     * Relative paths that differ from an earlier state -- written, added or
     * removed, which the caller treats alike: all three mean "analyse again".
     *
     * Sorted, so the same edit reported twice reads the same both times.
     *
     * @return list<string>
     */
    public function changesSince(self $previous): array
    {
        $changed = [];

        foreach ($this->stamps as $relative => $stamp) {
            if (($previous->stamps[$relative] ?? null) !== $stamp) {
                $changed[$relative] = true;
            }
        }

        foreach ($previous->stamps as $relative => $stamp) {
            if (! array_key_exists($relative, $this->stamps)) {
                $changed[$relative] = true;
            }
        }

        $paths = array_keys($changed);
        sort($paths);

        return $paths;
    }

    private static function stamp(string $absolute): string
    {
        $modified = @filemtime($absolute);

        return $modified === false ? '' : $modified.':'.(int) @filesize($absolute);
    }
}
