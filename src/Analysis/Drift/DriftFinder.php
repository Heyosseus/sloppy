<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis\Drift;

use Heyosseus\Sloppy\Ast\BlockSignature;
use Heyosseus\Sloppy\Ast\ProjectIndex;

/**
 * Every pair of method bodies close enough to each other to be worth a look.
 *
 * The corpus arrives sorted by token count, so a body only has to be compared
 * with the bodies after it until one is more than the budget longer -- at
 * which point nothing further along can be in range either. On a 1,075-file
 * application that is 9,526 comparisons rather than 152,076, and the frequency
 * bound inside BoundedTokenDistance discards almost all of those without
 * running a matrix.
 */
final class DriftFinder
{
    private bool $truncated = false;

    public function __construct(
        private readonly int $budget,
        private readonly float $maxRatio,
        private readonly int $minStatements,
        // Measured: 1.53 ms per BoundedTokenDistance::between() call on
        // 186-token bodies. 5,000 comparisons is a ~7.6s ceiling. Real code at
        // the default budget of 28 needs 348 (14x headroom); a widened budget
        // of 40 needs 2,673, still under. A corpus of same-length near-clones
        // has no such headroom -- 600 bodies of one length need 179,700
        // comparisons, 318s uncapped -- which is exactly what this ceiling is
        // for.
        private readonly int $maxComparisons = 5000,
    ) {}

    /**
     * @return list<DriftPair>
     */
    public function pairs(ProjectIndex $index): array
    {
        $this->truncated = false;

        $signatures = array_values(array_filter(
            $index->blockSignatures(),
            fn (BlockSignature $signature): bool => $signature->block->statementCount >= $this->minStatements,
        ));

        $count = count($signatures);
        $pairs = [];
        $comparisons = 0;

        // No deduplication step, because `$j` starting at `$i + 1` visits each
        // unordered pair exactly once and each body appears in the corpus once
        // -- `NodeHelper::methods()` reads a class's own statements without
        // recursing, so a nested class's methods are indexed under that class
        // and nowhere else. An earlier version carried a `$seen` map here; it
        // was provably unreachable, left a line no test could cover, and this
        // package ships a rule against defensive code that cannot fire.
        //
        // The comparison order -- and so the point at which the cap below cuts
        // the search off -- is fixed: the corpus is sorted by token count with
        // a total tie-break before it reaches here, and `$i` runs ascending
        // with `$j` from `$i + 1`. Same input, same order, same truncation
        // point, on every run and every machine.
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($signatures[$j]->tokenCount - $signatures[$i]->tokenCount > $this->budget) {
                    break;
                }

                if ($signatures[$i]->isSameBodyAs($signatures[$j])) {
                    continue;
                }

                // The cheap gates run first and uncounted. The cap has to
                // bound the expensive work -- the band-limited matrix -- and
                // on real code 11,063 pairs reach this point while only 348
                // get past `couldBeWithin()`. Counting arrivals rather than
                // survivors truncated a 73,737-line project at 0.22s and
                // silently dropped half its findings.
                if (! BoundedTokenDistance::couldBeWithin($signatures[$i], $signatures[$j], $this->budget)) {
                    continue;
                }

                if ($comparisons >= $this->maxComparisons) {
                    $this->truncated = true;
                    break 2;
                }

                $comparisons++;
                $pair = BoundedTokenDistance::between($signatures[$i], $signatures[$j], $this->budget);

                if (! $pair instanceof DriftPair || $pair->divergenceRatio > $this->maxRatio) {
                    continue;
                }

                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * Whether the last search stopped at its comparison ceiling.
     *
     * A truncated search is not a clean one, and this package's position is that
     * analysis the user silently lost is worse than analysis they were told
     * about -- the same reason framework-skipped rules are always named.
     */
    public function truncated(): bool
    {
        return $this->truncated;
    }

    /**
     * How many bodies, including this one, are in its near neighbourhood.
     *
     * Confidence rides on this. Two near-identical methods are as likely to be
     * independent as copied; four agreeing and a fifth diverging is an
     * argument.
     *
     * @param  list<DriftPair>  $pairs
     */
    public function familySize(array $pairs, BlockSignature $of): int
    {
        $members = [$of->identity() => true];

        foreach ($pairs as $pair) {
            foreach ([[$pair->a, $pair->b], [$pair->b, $pair->a]] as [$side, $other]) {
                if (! $side->isSameBodyAs($of)) {
                    continue;
                }

                $members[$other->identity()] = true;
            }
        }

        return count($members);
    }
}
