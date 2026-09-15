<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Contracts;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Finding;

/**
 * One detectable pattern.
 *
 * A rule is handed one already-parsed file at a time, plus a project-wide index
 * for the questions a single file cannot answer. It yields findings and does not
 * decide whether they matter -- thresholds, baselines and scoring happen
 * elsewhere.
 *
 * Rules must be deterministic and side-effect free: no disk writes, no network,
 * no clock. A detector that needs any of those is an evidence source instead,
 * and everything both kinds have in common lives on {@see Detector}.
 */
interface Rule extends Detector
{
    /**
     * @return iterable<Finding>
     */
    public function analyze(AnalysisContext $context): iterable;
}
