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
    private function __construct() {}

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
