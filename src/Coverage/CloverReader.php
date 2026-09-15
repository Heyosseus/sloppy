<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Coverage;

use XMLReader;

/**
 * Clover XML, the format PHPUnit and Pest write by default.
 *
 * Read with XMLReader rather than SimpleXML because a coverage report for a
 * large project runs to tens of megabytes and nothing here needs the tree --
 * two attributes per file is the whole requirement.
 *
 * When the XML extension is absent every read returns an empty map, so a
 * missing `ext-xml` costs ranking quality and nothing else. That is why it is
 * suggested rather than required.
 */
final class CloverReader
{
    private function __construct() {}

    public static function read(string $path, string $basePath): CoverageMap
    {
        if (! is_file($path) || ! class_exists(XMLReader::class)) {
            return CoverageMap::empty();
        }

        // No `open() === false` guard: `is_file()` has already answered the
        // question that fails in practice, and a failed open leaves `read()`
        // returning false on the first call, which produces the same empty map
        // through the same path.
        $reader = new XMLReader;
        @$reader->open($path);

        $prefix = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $ratios = [];
        $file = null;

        while (@$reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->name === 'file') {
                $file = self::relative((string) $reader->getAttribute('name'), $prefix);

                continue;
            }

            if ($reader->name === 'metrics' && $file !== null) {
                $statements = (int) $reader->getAttribute('statements');
                $covered = (int) $reader->getAttribute('coveredstatements');

                // Nothing to execute is not the same as nothing executed: an
                // interface has no statements and is not untested.
                $ratios[$file] = $statements === 0 ? 1.0 : $covered / $statements;
                $file = null;
            }
        }

        @$reader->close();

        $modified = filemtime($path);

        return new CoverageMap($ratios, $path, $modified === false ? null : $modified);
    }

    private static function relative(string $name, string $prefix): string
    {
        $normalised = str_replace('\\', '/', $name);

        return str_starts_with($normalised, $prefix)
            ? mb_substr($normalised, mb_strlen($prefix))
            : $normalised;
    }
}
