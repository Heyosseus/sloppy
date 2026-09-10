<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Contracts;

use Heyosseus\Sloppy\Git\DiffReport;

/**
 * Renders a diff comparison. Separate from {@see Formatter} because a diff
 * report answers a different question than a full run: not "how is this
 * codebase?" but "did this change make it worse?".
 */
interface DiffFormatter
{
    public function format(DiffReport $report): string;
}
