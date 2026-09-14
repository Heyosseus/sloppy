<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Drift\DriftCorpus;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Location;
use Heyosseus\Sloppy\Analysis\Severity;
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
        // Searched once for the whole project and then asked about this file,
        // rather than searched again for every file: the corpus does not
        // change between them, and re-deriving it per file is what turned a
        // third of a second into six minutes on a 1,075-file application.
        $corpus = DriftCorpus::for(
            $context->index,
            budget: max(1, $this->intOption('max_token_distance', 28)),
            maxRatio: min(1.0, max(0.001, $this->floatOption('max_divergence_ratio', 0.08))),
            minStatements: max(2, $this->intOption('min_statements', 8)),
            maxComparisons: max(1, $this->intOption('max_comparisons', 5000)),
        );

        yield from $this->shapeFindings($context, $corpus);
        yield from $this->maskedFindings($context, $corpus);
    }

    /**
     * @return iterable<Finding>
     */
    private function shapeFindings(AnalysisContext $context, DriftCorpus $corpus): iterable
    {
        // A silently degraded analysis is the thing this package promises not
        // to do: a run that hit the comparison ceiling did not see everything,
        // and every finding it does emit says so.
        $truncated = $corpus->truncated;

        foreach ($corpus->pairsIn($context->relativePath()) as $pair) {
            // One side, so the pair yields one finding. Corpus order makes `a`
            // the shorter body; that is a deterministic choice and not a claim
            // about which copy is wrong, which is why the message names both.
            $subject = $pair->a;
            $sibling = $pair->b;

            $family = $corpus->familySize($subject);

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
    private function maskedFindings(AnalysisContext $context, DriftCorpus $corpus): iterable
    {
        $truncated = $corpus->truncated;

        foreach ($corpus->maskedIn($context->relativePath()) as $masked) {
            $divergence = $masked['divergence'];
            $subject = $divergence['at'];

            // A renamed local is a rename. Only a different class or a
            // different literal is a candidate defect.
            if (str_starts_with($divergence['minority'], 'class:') || str_starts_with($divergence['minority'], 'lit:')) {
                yield $this->report(
                    context: $context,
                    at: new Location($subject->block->relativePath, $subject->block->line, $subject->block->endLine),
                    message: sprintf(
                        '%s is identical in shape to %d sibling(s) but uses %s where they all use %s.',
                        $subject->block->label(),
                        $masked['siblings'],
                        $this->readable($divergence['minority']),
                        $this->readable($divergence['majority']),
                    ),
                    suggestion: 'If this body was copied from one of its siblings, the odd value out is the most '
                        .'likely thing to have been missed. If it is deliberate, the value belongs in a parameter '
                        .'so the difference is visible at the call site.',
                    confidence: $this->confidenceFrom(66, [
                        $masked['siblings'] >= 3,
                        str_starts_with($divergence['minority'], 'class:'),
                    ], 8, 88),
                    fingerprint: $subject->block->className.'::'.$subject->block->methodName.'~'.$divergence['minority'],
                    metrics: [
                        'siblings' => $masked['siblings'],
                        'diverged' => $this->readable($divergence['minority']),
                        'siblings_use' => $this->readable($divergence['majority']),
                        ...($truncated ? ['search_truncated' => true] : []),
                    ],
                );
            }
        }
    }

    private function readable(string $value): string
    {
        return str_contains($value, ':') ? substr($value, strpos($value, ':') + 1) : $value;
    }
}
