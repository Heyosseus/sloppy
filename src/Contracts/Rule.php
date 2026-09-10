<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Contracts;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;

/**
 * One detectable pattern.
 *
 * A rule is handed one already-parsed file at a time, plus a project-wide index
 * for the questions a single file cannot answer. It yields findings and does not
 * decide whether they matter -- thresholds, baselines and scoring happen
 * elsewhere.
 *
 * Rules must be deterministic and side-effect free: no disk writes, no network,
 * no clock.
 */
interface Rule
{
    /**
     * Stable identifier, e.g. `SL101`. Used in configuration, baselines and
     * suppression comments, so it must never change once released.
     */
    public function id(): string;

    /**
     * Short human name, e.g. `God Method`.
     */
    public function name(): string;

    /**
     * One line describing what the rule looks for. Used in documentation.
     */
    public function description(): string;

    /**
     * Why the pattern may be a problem. Shown with every finding, because a
     * report that only names a smell teaches nobody anything.
     */
    public function explanation(): string;

    public function category(): Category;

    /**
     * Severity applied to this rule's findings, after any user override.
     */
    public function severity(): Severity;

    /**
     * @return iterable<Finding>
     */
    public function analyze(AnalysisContext $context): iterable;
}
