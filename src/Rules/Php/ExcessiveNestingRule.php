<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node;

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
        return 'Flags methods whose conditionals, loops and try blocks nest deeper than the configured limit.';
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

        foreach ($context->classLikes() as $classLike) {
            $className = NodeHelper::shortName($classLike) ?? 'anonymous class';

            foreach (NodeHelper::methods($classLike) as $method) {
                if ($method->stmts === null || $method->stmts === []) {
                    continue;
                }

                $depth = NodeHelper::maxNestingDepth($method);

                if ($depth <= $maxDepth) {
                    continue;
                }

                $deepest = NodeHelper::deepestNestedNode($method);

                yield $this->report(
                    context: $context,
                    at: $deepest instanceof Node ? $deepest : $method,
                    message: sprintf(
                        '%s::%s() nests control flow %d levels deep, past the limit of %d.',
                        $className,
                        $method->name->toString(),
                        $depth,
                        $maxDepth,
                    ),
                    suggestion: 'Invert the outer conditions into guard clauses that return early, then extract the '
                        .'remaining inner block into its own method. Both changes flatten the method without '
                        .'changing behaviour.',
                    confidence: min(95, 70 + ($depth - $maxDepth) * 8),
                    fingerprint: $className.'::'.$method->name->toString(),
                    metrics: [
                        'depth' => $depth,
                        'max_depth' => $maxDepth,
                    ],
                );
            }
        }
    }
}
