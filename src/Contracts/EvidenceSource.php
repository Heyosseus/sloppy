<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Contracts;

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Evidence\EvidenceContext;

/**
 * A finding that comes from comparing revisions rather than from reading code.
 *
 * Evidence sources run only where there is a base to compare against, so a
 * plain `sloppy scan` produces none: "grew" is meaningless with one point in
 * time.
 *
 * For the same reason their findings are reported and ranked but never scored.
 * The score is a function of the tree alone, and a score that changed depending
 * on whether a base revision was passed would make `sloppy scan` and
 * `sloppy diff main` disagree about the same working tree.
 */
interface EvidenceSource extends Detector
{
    /**
     * @return iterable<Finding>
     */
    public function evidence(EvidenceContext $context): iterable;
}
