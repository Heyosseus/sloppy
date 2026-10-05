<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\Finding;

/**
 * Fingerprints that tell apart findings sharing an identity.
 *
 * `Finding::identity()` deliberately leaves out the line, so two identical
 * findings in one file -- the same swallowed exception twice in a method --
 * share it. A consumer that deduplicates by fingerprint, as GitLab and GitHub
 * code scanning both do, would then show one of them. The first occurrence
 * keeps the bare identity, so existing fingerprints do not change; later ones
 * add their ordinal, which is stable for as long as the findings' order is.
 */
final readonly class OccurrenceFingerprints
{
    /**
     * @param  list<Finding>  $findings
     * @return list<string> One fingerprint per finding, in the same order.
     */
    public function for(array $findings): array
    {
        $seen = [];
        $fingerprints = [];

        foreach ($findings as $finding) {
            $identity = $finding->identity();
            $occurrence = $seen[$identity] = ($seen[$identity] ?? 0) + 1;

            $fingerprints[] = $occurrence === 1
                ? $identity
                : substr(hash('sha256', $identity."\0".$occurrence), 0, 16);
        }

        return $fingerprints;
    }
}
