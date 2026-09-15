<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Contracts;

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;

/**
 * Anything that can produce a finding and describe itself.
 *
 * Rules are not the only producers. `SL502` compares a baseline file across two
 * git revisions, which a rule may not do -- a rule is handed one parsed file
 * and forbidden to read from disk. But the configuration's enable list, the
 * baseline, `sloppy rules` and every formatter need the same six answers from
 * both, so those six live here rather than being asked of a `Rule`.
 */
interface Detector
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
     * One line describing what this looks for. Used in documentation.
     */
    public function description(): string;

    /**
     * Why the pattern may be a problem. Shown with every finding, because a
     * report that only names a smell teaches nobody anything.
     */
    public function explanation(): string;

    public function category(): Category;

    /**
     * Severity applied to these findings, after any user override.
     */
    public function severity(): Severity;
}
