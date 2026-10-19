<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests\Support;

/**
 * Delete a directory tree that something else may be changing.
 *
 * Tolerant on purpose, in three directions at once: Windows refuses to delete
 * a file whose handle is still open, git repacks `.git/objects` while a test
 * repository is being torn down, and PHP's stat cache reports a directory for
 * a moment after it has gone. None of those are worth failing a test that has
 * already proved what it set out to prove.
 *
 * It lives here rather than beside each caller because there were two copies
 * of it, one hardened and one not -- which is the shape `SL111` warns about,
 * and which is exactly how the unhardened copy went on failing CI after the
 * other one stopped.
 */
final class TempTree
{
    /**
     * Every tree created through {@see track()}, removed when the process
     * ends unless a test removed it first.
     *
     * @var array<string, true>
     */
    private static array $tracked = [];

    private function __construct() {}

    /**
     * Have a throwaway tree removed when the process ends.
     *
     * A test that fails, or simply forgets, never reaches its own clean-up,
     * and the trees used to pile up in the system temp directory by the
     * thousand -- where every test that looks around a temporary directory
     * then had to walk past all of them.
     */
    public static function track(string $path): string
    {
        if (self::$tracked === []) {
            register_shutdown_function(static function (): void {
                foreach (array_keys(self::$tracked) as $tracked) {
                    self::remove($tracked);
                }
            });
        }

        self::$tracked[$path] = true;

        return $path;
    }

    public static function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        // Suppressed and checked rather than trusted: `is_dir()` above can be
        // answering from the stat cache about a directory that is already
        // gone, and a warning here would fail the test rather than the tidy-up.
        $entries = @scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.'/'.$entry;

            if (is_dir($full)) {
                self::remove($full);

                continue;
            }

            @chmod($full, 0o666);
            @unlink($full);
        }

        @rmdir($path);
    }
}
