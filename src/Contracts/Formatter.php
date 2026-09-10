<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Contracts;

use Heyosseus\Sloppy\Analysis\AnalysisResult;

/**
 * Renders an analysis result for a particular audience.
 *
 * Formatters return a string rather than writing to output, so they can be
 * asserted on in tests and reused by anything that needs a report -- a command,
 * a CI annotation, an editor.
 */
interface Formatter
{
    public function format(AnalysisResult $result): string;
}
