<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use JsonException;
use RuntimeException;

/**
 * Every JSON report goes out through here.
 *
 * Source files are not guaranteed to be UTF-8, and a finding quoting a
 * Latin-1 string literal used to turn a whole report into `{}` -- a valid,
 * empty, green-looking document. Invalid bytes are replaced instead, and any
 * failure that remains is raised rather than written, so the command exits 2
 * instead of publishing nothing as if it were a result.
 */
final readonly class JsonEncoder
{
    public function __construct(private int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) {}

    /**
     * @throws RuntimeException When the value cannot be encoded at all.
     */
    public function encode(mixed $value): string
    {
        try {
            return json_encode($value, $this->flags | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The report could not be encoded as JSON: '.$exception->getMessage(), 0, $exception);
        }
    }
}
