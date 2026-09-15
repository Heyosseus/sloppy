<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Coverage;

use XMLReader;

/**
 * Cobertura XML, the format most CI coverage services expect.
 *
 * Cobertura states a line rate directly, so unlike Clover there is nothing to
 * divide -- which also means a file with no executable lines already arrives as
 * a rate of 1 and needs no special case.
 */
final class CoberturaReader
{
    private function __construct() {}

    public static function read(string $path, string $basePath): CoverageMap
    {
        if (! is_file($path) || ! class_exists(XMLReader::class)) {
            return CoverageMap::empty();
        }

        // See CloverReader: a failed open needs no guard of its own, because
        // `read()` returning false on the first call produces the same empty
        // map through the same path.
        $reader = new XMLReader;
        @$reader->open($path);

        $prefix = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $ratios = [];

        while (@$reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'class') {
                continue;
            }

            $filename = $reader->getAttribute('filename');

            if (! is_string($filename) || trim($filename) === '') {
                continue;
            }

            $normalised = str_replace('\\', '/', $filename);
            $relative = str_starts_with($normalised, $prefix)
                ? mb_substr($normalised, mb_strlen($prefix))
                : $normalised;

            $ratios[$relative] = max(0.0, min(1.0, (float) $reader->getAttribute('line-rate')));
        }

        @$reader->close();

        $modified = filemtime($path);

        return new CoverageMap($ratios, $path, $modified === false ? null : $modified);
    }
}
