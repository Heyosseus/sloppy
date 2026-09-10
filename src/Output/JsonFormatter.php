<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Contracts\Formatter;

/**
 * The machine-facing report.
 *
 * The shape is a published contract: `schema` is bumped when a field changes
 * meaning, and keys are only ever added within a schema version. Findings
 * appear in the analyser's canonical order, so the output of two runs over the
 * same code is byte-identical and safe to diff in CI.
 */
final readonly class JsonFormatter implements Formatter
{
    public const int SCHEMA = 1;

    public function __construct(private bool $pretty = true) {}

    public function format(AnalysisResult $result): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

        if ($this->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $encoded = json_encode([
            'schema' => self::SCHEMA,
            'tool' => 'sloppy',
            ...$result->toArray(),
        ], $flags);

        return ($encoded === false ? '{}' : $encoded)."\n";
    }
}
