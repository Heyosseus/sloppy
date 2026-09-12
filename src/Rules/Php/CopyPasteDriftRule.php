<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Drift\DriftFinder;
use Heyosseus\Sloppy\Analysis\Drift\DriftPair;
use Heyosseus\Sloppy\Analysis\Drift\MaskedDivergence;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Location;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\BlockSignature;
use Heyosseus\Sloppy\Rules\BaseRule;

/**
 * SL111 -- a body that is almost, but not quite, one of its siblings.
 *
 * SL104 reports bodies that are structurally identical, and every other tool
 * in this space reports either identical code or different code. The space
 * between is where copy-paste bugs live: five near-identical handlers and one
 * with a flipped comparison, a missing guard, or the wrong class newed up.
 *
 * The argument is relational, not provable, so confidence rises with the size
 * of the agreeing family and never reaches certainty.
 */
final class CopyPasteDriftRule extends BaseRule
{
    public function id(): string
    {
        return 'SL111';
    }

    public function name(): string
    {
        return 'Copy-Paste Drift';
    }

    public function description(): string
    {
        return 'Flags a method body that is nearly identical to one or more siblings, where the difference looks like an unfinished copy rather than a deliberate variation.';
    }

    public function explanation(): string
    {
        return 'A body that matches its siblings everywhere but one place was probably copied and then edited in one '
            .'copy only -- a guard added here and not there, a comparison flipped, a different class constructed. '
            .'Structural duplication detection cannot see this, because it is looking for bodies that match exactly, '
            .'and exact matches are the case where there is no bug. Confirm which copy is right before changing '
            .'either: the majority is not automatically correct, it is only the more likely to be.';
    }

    public function category(): Category
    {
        return Category::Duplication;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::High;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $finder = new DriftFinder(
            budget: max(1, $this->intOption('max_token_distance', 28)),
            maxRatio: min(1.0, max(0.001, $this->floatOption('max_divergence_ratio', 0.08))),
            minStatements: max(2, $this->intOption('min_statements', 8)),
            maxComparisons: max(1, $this->intOption('max_comparisons', 5000)),
        );

        $pairs = $finder->pairs($context->index);

        // A silently degraded analysis is the thing this package promises not
        // to do: a run that hit the comparison ceiling did not see everything,
        // and every finding it does emit says so.
        $truncated = $finder->truncated();

        yield from $this->shapeFindings($context, $finder, $pairs, $truncated);
        yield from $this->maskedFindings($context, $truncated);
    }

    /**
     * @param  list<DriftPair>  $pairs
     * @return iterable<Finding>
     */
    private function shapeFindings(AnalysisContext $context, DriftFinder $finder, array $pairs, bool $truncated): iterable
    {
        foreach ($pairs as $pair) {
            // One side, so the pair yields one finding. Corpus order makes `a`
            // the shorter body; that is a deterministic choice and not a claim
            // about which copy is wrong, which is why the message names both.
            $subject = $pair->a;
            $sibling = $pair->b;

            if ($subject->block->relativePath !== $context->relativePath()) {
                continue;
            }

            $family = $finder->familySize($pairs, $subject);

            yield $this->report(
                context: $context,
                at: new Location($subject->block->relativePath, $subject->block->line, $subject->block->endLine),
                message: sprintf(
                    '%s differs from %s by %d token(s) out of %d -- %.1f%% of the body.',
                    $subject->block->label(),
                    $sibling->block->label().' at '.$sibling->block->reference(),
                    $pair->distance,
                    $subject->tokenCount,
                    100 * $pair->divergenceRatio,
                ),
                suggestion: 'Read the two side by side and decide which is right. If the difference is deliberate, '
                    .'the shared part is worth extracting so the next change cannot land in one copy only.',
                confidence: $this->confidenceFrom(60, [
                    $pair->divergenceRatio <= 0.02,
                    $family >= 3,
                    $sibling->block->relativePath !== $subject->block->relativePath,
                ], 8, 88),
                fingerprint: $subject->block->className.'::'.$subject->block->methodName.'~'.$sibling->block->className.'::'.$sibling->block->methodName,
                metrics: [
                    'token_distance' => $pair->distance,
                    'token_count' => $subject->tokenCount,
                    // Where the two streams first part company. The spec asks
                    // for the divergence position and not merely the pair, and
                    // a machine reading the JSON can seek to it.
                    'divergence_at_token' => $pair->divergenceIndex,
                    'divergence_ratio' => round($pair->divergenceRatio, 4),
                    'family_size' => $family,
                    ...($truncated ? ['search_truncated' => true] : []),
                ],
            );
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function maskedFindings(AnalysisContext $context, bool $truncated): iterable
    {
        foreach ($this->hashGroups($context) as $group) {
            foreach (MaskedDivergence::inGroup($group) as $divergence) {
                $subject = $divergence['at'];

                if ($subject->block->relativePath !== $context->relativePath()) {
                    continue;
                }

                // A renamed local is a rename. Only a different class or a
                // different literal is a candidate defect.
                if (! str_starts_with($divergence['minority'], 'class:') && ! str_starts_with($divergence['minority'], 'lit:')) {
                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: new Location($subject->block->relativePath, $subject->block->line, $subject->block->endLine),
                    message: sprintf(
                        '%s is identical in shape to %d sibling(s) but uses %s where they all use %s.',
                        $subject->block->label(),
                        count($group) - 1,
                        $this->readable($divergence['minority']),
                        $this->readable($divergence['majority']),
                    ),
                    suggestion: 'If this body was copied from one of its siblings, the odd value out is the most '
                        .'likely thing to have been missed. If it is deliberate, the value belongs in a parameter '
                        .'so the difference is visible at the call site.',
                    confidence: $this->confidenceFrom(66, [
                        count($group) >= 4,
                        str_starts_with($divergence['minority'], 'class:'),
                    ], 8, 88),
                    fingerprint: $subject->block->className.'::'.$subject->block->methodName.'~'.$divergence['minority'],
                    metrics: [
                        'siblings' => count($group) - 1,
                        'diverged' => $this->readable($divergence['minority']),
                        'siblings_use' => $this->readable($divergence['majority']),
                        ...($truncated ? ['search_truncated' => true] : []),
                    ],
                );
            }
        }
    }

    /**
     * Bodies grouped by structural hash, only where a majority could exist.
     *
     * The floor of three here is a policy choice about which groups are worth
     * examining, and it is deliberately a separate decision from the identical
     * floor inside MaskedDivergence, which is a soundness precondition: with
     * two bodies there is a difference but no way to say which is the mistake.
     * Raising this one does not make that one adjustable.
     *
     * @return list<list<BlockSignature>>
     */
    private function hashGroups(AnalysisContext $context): array
    {
        $minStatements = max(2, $this->intOption('min_statements', 8));
        $groups = [];

        foreach ($context->index->blockSignatures() as $signature) {
            if ($signature->block->statementCount < $minStatements) {
                continue;
            }

            $groups[$signature->hash][] = $signature;
        }

        return array_values(array_filter($groups, static fn (array $group): bool => count($group) >= 3));
    }

    private function readable(string $value): string
    {
        return str_contains($value, ':') ? substr($value, strpos($value, ':') + 1) : $value;
    }
}
