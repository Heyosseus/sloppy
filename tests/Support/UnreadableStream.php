<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests\Support;

/**
 * A stream wrapper for a path that exists and cannot be read.
 *
 * The combination is ordinary in production -- a baseline written by CI under
 * another user, a file on a mount that has gone away -- and impossible to
 * arrange from a test on Windows, where permissions do not stop a read. A
 * wrapper produces exactly that state, on both platforms, without touching the
 * real filesystem.
 *
 * @see https://www.php.net/manual/en/class.streamwrapper.php
 */
final class UnreadableStream
{
    public const string SCHEME = 'sloppy-unreadable';

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

    /**
     * A path under this scheme, e.g. `sloppy-unreadable://baseline.json`.
     */
    public static function path(string $name): string
    {
        return self::SCHEME.'://'.$name;
    }

    /**
     * Every open fails, which is the whole point.
     */
    public function stream_open(): bool
    {
        return false;
    }

    /**
     * ...but the path reports itself as a regular file, so `is_file()` is
     * true and the caller gets as far as trying to read it.
     *
     * @return array<int|string, int>
     */
    public function url_stat(): array
    {
        return ['mode' => 0o100_000, 'size' => 1];
    }
}
