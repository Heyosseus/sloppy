<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\CodeUnit;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;

/**
 * SL103 -- control flow nested deeply enough that the happy path is buried.
 */
final class ExcessiveNestingRule extends BaseRule
{
    public function id(): string
    {
        return 'SL103';
    }

    public function name(): string
    {
        return 'Excessive Nesting';
    }

    public function description(): string
    {
        return 'Flags methods, functions and top-level code whose conditionals, loops and try blocks nest deeper than the configured limit.';
    }

    public function explanation(): string
    {
        return 'Every level of nesting is another condition the reader has to keep in their head to know whether a '
            .'line runs. Deeply nested code also tends to hide its happy path at the bottom of the well. Closures '
            .'are not counted as nesting, because passing one to a transaction or a collection pipeline is '
            .'idiomatic rather than a smell.';
    }

    public function category(): Category
    {
        return Category::Complexity;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $maxDepth = max(1, $this->intOption('max_depth', 4));

        // Functions, property hooks and top-level code -- a routes file full
        // of closures -- bury a happy path just as well as a method does.
        foreach (CodeUnit::inFile($context->file, withTopLevel: true) as $unit) {
            if (! $unit->hasBody()) {
                continue;
            }

            $depth = NodeHelper::maxNestingDepth($unit->root());

            if ($depth <= $maxDepth) {
                continue;
            }

            $deepest = NodeHelper::deepestNestedNode($unit->root());

            yield $this->report(
                context: $context,
                at: $deepest ?? $unit->node ?? $context->locateLine(1),
                message: sprintf(
                    '%s nests control flow %d levels deep, past the limit of %d.',
                    $unit->subject(),
                    $depth,
                    $maxDepth,
                ),
                suggestion: 'Invert the outer conditions into guard clauses that return early, then extract the '
                    .'remaining inner block into its own method. Both changes flatten the method without '
                    .'changing behaviour.',
                confidence: min(95, 70 + ($depth - $maxDepth) * 8),
                fingerprint: $unit->label(),
                metrics: [
                    'depth' => $depth,
                    'max_depth' => $maxDepth,
                ],
            );
        }
    }
}
