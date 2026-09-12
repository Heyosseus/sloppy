<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis\Drift;

use Heyosseus\Sloppy\Ast\BlockSignature;

/**
 * Levenshtein over two token streams, refusing to be slow.
 *
 * Three gates, cheapest first. A length difference larger than the budget
 * cannot be closed by the budget's worth of edits. A token-frequency L1
 * distance is an exact lower bound on the edit distance -- an insertion or
 * deletion moves it by one and a substitution by two -- so half of it
 * exceeding the budget rules the pair out without any dynamic programming at
 * all. Only what survives both reaches the matrix, and the matrix is band
 * limited and abandoned the moment every reachable cell exceeds the budget.
 *
 * The first two gates are not an optimisation detail. A naive banded matrix
 * over the 9,526 candidate pairs of a 1,075-file application took 10.57s,
 * which is more than the whole analysis it would be part of.
 */
final class BoundedTokenDistance
{
    private function __construct() {}

    /**
     * Null when the two are identical, or further apart than the budget.
     */
    public static function between(BlockSignature $a, BlockSignature $b, int $budget): ?DriftPair
    {
        if (! self::couldBeWithin($a, $b, $budget)) {
            return null;
        }

        $left = $a->tokenList();
        $right = $b->tokenList();

        $distance = self::distance($left, $right, $budget);

        if ($distance === null || $distance === 0) {
            return null;
        }

        $smaller = max(1, min($a->tokenCount, $b->tokenCount));

        return new DriftPair(
            a: $a,
            b: $b,
            distance: $distance,
            divergenceRatio: $distance / $smaller,
            divergenceIndex: self::firstDivergence($left, $right),
        );
    }

    /**
     * Whether this pair is worth the matrix at all.
     *
     * Three gates, none of which touches a token: a length difference wider
     * than the budget cannot be closed by the budget's worth of edits;
     * identical shapes are SL104's finding rather than SL111's; and half the
     * token-frequency L1 distance is an exact lower bound on the edit
     * distance, so exceeding the budget rules the pair out outright.
     *
     * This is public because it is the difference between cheap work and
     * expensive work, and the caller has to be able to tell them apart: on a
     * 1,075-file application 11,063 pairs reach here and only 348 get past,
     * so a caller that counted calls to `between()` would be counting
     * rejections 32 times more often than comparisons.
     */
    public static function couldBeWithin(BlockSignature $a, BlockSignature $b, int $budget): bool
    {
        return abs($a->tokenCount - $b->tokenCount) <= $budget
            && $a->hash !== $b->hash
            && self::frequencyLowerBound($a, $b) <= $budget;
    }

    /**
     * Half the L1 distance between the two token-frequency maps, which no
     * sequence of edits smaller than can bridge.
     */
    private static function frequencyLowerBound(BlockSignature $a, BlockSignature $b): int
    {
        $difference = 0;

        foreach ($a->frequencies as $token => $count) {
            $difference += abs($count - ($b->frequencies[$token] ?? 0));
        }

        foreach ($b->frequencies as $token => $count) {
            if (! isset($a->frequencies[$token])) {
                $difference += $count;
            }
        }

        return (int) ceil($difference / 2);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return int|null Null when the distance exceeds the budget.
     */
    private static function distance(array $a, array $b, int $budget): ?int
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 || $m === 0) {
            return max($n, $m) <= $budget ? max($n, $m) : null;
        }

        $unreachable = $budget + 1;

        /** @var array<int, int> $previous */
        $previous = [];

        for ($j = 0; $j <= min($m, $budget); $j++) {
            $previous[$j] = $j;
        }

        for ($i = 1; $i <= $n; $i++) {
            $low = max(1, $i - $budget);
            $high = min($m, $i + $budget);

            /** @var array<int, int> $current */
            $current = [];
            $current[$low - 1] = $low - 1 === 0 ? $i : $unreachable;

            $best = $unreachable;

            for ($j = $low; $j <= $high; $j++) {
                $substitution = ($previous[$j - 1] ?? $unreachable) + ($a[$i - 1] === $b[$j - 1] ? 0 : 1);
                $deletion = ($previous[$j] ?? $unreachable) + 1;
                $insertion = ($current[$j - 1] ?? $unreachable) + 1;

                $cell = min($substitution, $deletion, $insertion);
                $current[$j] = $cell;
                $best = min($best, $cell);
            }

            if ($best > $budget) {
                return null;
            }

            $previous = $current;
        }

        $distance = $previous[$m] ?? $unreachable;

        return $distance > $budget ? null : $distance;
    }

    /**
     * Where the two streams first disagree, so a finding can point at the
     * divergence instead of the whole body.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function firstDivergence(array $a, array $b): int
    {
        $limit = min(count($a), count($b));

        for ($i = 0; $i < $limit; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $i;
            }
        }

        return $limit;
    }
}
