<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis\Drift;

use Heyosseus\Sloppy\Ast\BlockSignature;

/**
 * Two bodies that are nearly the same, and how nearly.
 *
 * The ratio matters more than the distance. Eleven tokens apart in a
 * 600-token method is two payment gateways that diverged; eleven tokens apart
 * in a 50-token method is two different methods that happen to be short. Both
 * were measured on the same application.
 */
final readonly class DriftPair
{
    public function __construct(
        public BlockSignature $a,
        public BlockSignature $b,
        public int $distance,
        public float $divergenceRatio,
        public int $divergenceIndex,
    ) {}

}
