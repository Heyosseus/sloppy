<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\ProjectIndex;

/**
 * How much of the project is downstream of a finding.
 *
 * A god method in a class nothing else references is a local problem. The same
 * method in a class forty files reach into is the same code and a different
 * decision, and no severity or confidence value can express that difference
 * because both are properties of the finding rather than of its position.
 *
 * The subject is resolved from the finding's own location rather than reported
 * by each rule: the class declaration that encloses the reported line is the
 * thing callers depend on, which is true for every rule without any of them
 * having to say so. A rule that knows better can still override it by putting
 * a fully qualified name in a `blast_subject` metric.
 *
 * That key is deliberately not `subject`. Two shipped rules already use
 * `subject` for something else entirely -- `SL204` puts the queried model's
 * short name there and `SL110` a guard expression -- and reading those as a
 * class to resolve silently suppressed reach for every finding they produce.
 *
 * A finding whose subject cannot be resolved gets **no** `blast_radius` metric.
 * Zero would read as "nothing uses this", which is a different and much
 * stronger claim than "this was not measured".
 */
final readonly class BlastRadiusEnricher
{
    public function __construct(private ProjectIndex $index) {}

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    public function enrich(array $findings): array
    {
        $enriched = [];

        foreach ($findings as $finding) {
            $subject = $this->subjectOf($finding);

            if ($subject === null) {
                $enriched[] = $finding;

                continue;
            }

            $enriched[] = $finding->withMetrics([
                'subject' => $subject,
                'blast_radius' => $this->index->usageCount($subject),
            ]);
        }

        return $enriched;
    }

    /**
     * The fully qualified name whose usages measure this finding's reach.
     *
     * A rule-declared subject is only taken when the project actually knows
     * that name -- either it is declared here or something references it.
     * Otherwise `usageCount()` would answer 0 for a name it has never seen,
     * and reporting that as a blast radius would state as measured fact the
     * one thing this class refuses to claim.
     */
    private function subjectOf(Finding $finding): ?string
    {
        $declared = $finding->metrics['blast_subject'] ?? null;

        if (is_string($declared) && $declared !== '' && $this->isKnown($declared)) {
            return $declared;
        }

        // An override the project has never heard of falls back to the
        // enclosing class rather than to nothing: a rule being wrong about a
        // name is no reason to discard a measurement available anyway.
        return $this->enclosingClass($finding);
    }

    /**
     * Whether the project knows this name at all.
     *
     * `usageCount()` answers 0 for a name it has never seen, and reporting
     * that as a blast radius would state as measured fact the one thing this
     * class refuses to claim.
     */
    private function isKnown(string $fqn): bool
    {
        return isset($this->index->classes()[$fqn]) || $this->index->usagesOf($fqn) !== [];
    }

    /**
     * The innermost class declaration containing the reported line.
     *
     * Innermost matters: an anonymous class inside a method of a large class
     * is indexed separately, and a finding inside it is about the anonymous
     * class rather than about its host. Ties are broken by the smaller span,
     * which is the more specific answer.
     */
    private function enclosingClass(Finding $finding): ?string
    {
        $best = null;
        $bestSpan = PHP_INT_MAX;

        foreach ($this->index->classes() as $fqn => $summary) {
            if ($summary->relativePath !== $finding->location->relativePath) {
                continue;
            }

            if (! $this->contains($summary, $finding->location->line)) {
                continue;
            }

            if ($summary->lineSpan >= $bestSpan) {
                continue;
            }

            $best = $fqn;
            $bestSpan = $summary->lineSpan;
        }

        return $best;
    }

    /**
     * A span is at least one line -- a class declared and closed on the same
     * line spans that line -- so the range covers the single-line case too.
     */
    private function contains(ClassSummary $summary, int $line): bool
    {
        return $line >= $summary->line && $line <= $summary->line + $summary->lineSpan;
    }
}
