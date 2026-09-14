<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests\Support;

/**
 * A path that reports itself as a directory and refuses to be opened.
 *
 * The pair is ordinary during a teardown: git repacks `.git/objects` while the
 * walk that is deleting it has already decided a loose-object directory is
 * there, and PHP's stat cache keeps saying so after it is gone. The result is
 * a `scandir()` warning in the middle of cleaning up, which fails a test that
 * had already made its point.
 *
 * Arranging that race for real is not possible from a test. A wrapper produces
 * the same state deterministically, on both platforms.
 *
 * @see UnreadableStream, which does the same for a file that cannot be read.
 */
final class UnreadableDirectory
{
    public const string SCHEME = 'sloppy-unreadable-dir';

    /** @var resource|null */
    public $context;

    public static function register(): void
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public static function path(string $name): string
    {
        return self::SCHEME.'://'.$name;
    }

    /**
     * Every attempt to list it fails, which is the whole point.
     */
    public function dir_opendir(): bool
    {
        return false;
    }

    /**
     * ...but it stats as a directory, so the caller gets as far as trying.
     *
     * @return array<int|string, int>
     */
    public function url_stat(): array
    {
        return ['mode' => 0o040_000];
    }
}
