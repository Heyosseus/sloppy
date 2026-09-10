<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Contracts\DiffFormatter;
use Heyosseus\Sloppy\Git\DiffReport;

/**
 * Diff output for CI: the same stable contract as {@see JsonFormatter}, with
 * findings split into new, existing and resolved.
 */
final readonly class DiffJsonFormatter implements DiffFormatter
{
    public function __construct(private bool $pretty = true) {}

    public function format(DiffReport $report): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

        if ($this->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $encoded = json_encode($report->toArray(), $flags);

        return ($encoded === false ? '{}' : $encoded)."\n";
    }
}
