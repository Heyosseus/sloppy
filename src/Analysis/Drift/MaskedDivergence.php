<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis\Drift;

use Heyosseus\Sloppy\Ast\BlockSignature;

/**
 * The divergence that structural hashing is blind to by design.
 *
 * Bodies sharing a hash have, by construction, masked-value lists of the same
 * length in the same order -- the hash is a function of the tokens and the
 * masked values were collected in the same traversal. So position n in one
 * list describes the same place in the code as position n in another, and a
 * position where every body but one agrees is a body that was copied and not
 * finished.
 *
 * This is where `new StripeGateway()` against `new PaypalGateway()` is caught.
 * Both hash to 2329762072c8 and SL104 advises merging them.
 */
final class MaskedDivergence
{
    private function __construct() {}

    /**
     * @param  list<BlockSignature>  $group  Bodies that share a structural hash.
     * @return list<array{index: int, majority: string, minority: string, at: BlockSignature}>
     */
    public static function inGroup(array $group): array
    {
        // Three is the floor for a majority to exist. With two bodies there is
        // a difference but no way to say which side is the mistake. This is a
        // soundness precondition, not a policy choice -- Task 5's identical
        // floor in CopyPasteDriftRule::hashGroups() is the policy one.
        if (count($group) < 3) {
            return [];
        }

        $length = count($group[0]->maskedValues);

        foreach ($group as $signature) {
            if (count($signature->maskedValues) !== $length) {
                // Equal hashes should guarantee equal length. If they do not,
                // the assumption this comparison rests on is broken and
                // guessing would be worse than saying nothing.
                return [];
            }
        }

        $divergences = [];

        for ($index = 0; $index < $length; $index++) {
            $counts = [];

            foreach ($group as $signature) {
                $value = $signature->maskedValues[$index];
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }

            if (count($counts) !== 2) {
                // One value is agreement; three or more is a parameter.
                continue;
            }

            $minority = null;
            $majority = null;

            foreach ($counts as $value => $count) {
                if ($count === 1) {
                    $minority = (string) $value;

                    continue;
                }

                $majority = (string) $value;
            }

            if ($minority === null || $majority === null) {
                continue;
            }

            foreach ($group as $signature) {
                if ($signature->maskedValues[$index] !== $minority) {
                    continue;
                }

                $divergences[] = [
                    'index' => $index,
                    'majority' => $majority,
                    'minority' => $minority,
                    'at' => $signature,
                ];
            }
        }

        return $divergences;
    }
}
