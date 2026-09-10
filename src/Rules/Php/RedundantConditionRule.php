<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\ConditionShape;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Nop;

/**
 * SL108 -- conditions that cannot change the outcome.
 *
 * Only unmistakable cases are reported: the same test written twice, a literal
 * `true` or `false` guard, and a condition repeated on both sides of `&&` or
 * `||`. Anything requiring value tracking to prove is left alone.
 */
final class RedundantConditionRule extends BaseRule
{
    public function id(): string
    {
        return 'SL108';
    }

    public function name(): string
    {
        return 'Redundant Condition';
    }

    public function description(): string
    {
        return 'Flags conditions re-tested immediately inside themselves, repeated within one if/elseif chain, duplicated across a boolean operator, or written as a literal true/false.';
    }

    public function explanation(): string
    {
        return 'A condition that has already been established cannot change the outcome, so re-testing it adds a '
            .'branch the reader has to verify and a branch tests can never cover. It usually appears when code is '
            .'assembled from pieces that each defended themselves.';
    }

    public function category(): Category
    {
        return Category::Readability;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Low;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        foreach ($context->classLikes() as $classLike) {
            $className = NodeHelper::shortName($classLike) ?? 'anonymous class';

            yield from $this->literalConditions($context, $classLike, $className);
            yield from $this->nestedRepeats($context, $classLike, $className);
            yield from $this->chainRepeats($context, $classLike, $className);
            yield from $this->duplicatedOperands($context, $classLike, $className);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function literalConditions(AnalysisContext $context, ClassLike $classLike, string $className): iterable
    {
        foreach (NodeHelper::find($classLike, If_::class) as $if) {
            if (! $if->cond instanceof ConstFetch) {
                continue;
            }

            $literal = mb_strtolower($if->cond->name->toString());

            if ($literal !== 'true' && $literal !== 'false') {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $if,
                message: sprintf('%s has an `if (%s)` whose branch is decided before it runs.', $className, $literal),
                suggestion: $literal === 'true'
                    ? 'Remove the condition and keep the body.'
                    : 'Remove the branch, or restore the condition it was meant to have.',
                confidence: 95,
                fingerprint: sprintf('%s:literal-if:%s:%s', $className, $literal, $this->scopeOf($if)),
                metrics: ['literal' => $literal],
            );
        }
    }

    /**
     * `if (X) { if (X) { ... } }` -- the inner test is the first thing the
     * outer branch does, so nothing can have changed in between.
     *
     * @return iterable<Finding>
     */
    private function nestedRepeats(AnalysisContext $context, ClassLike $classLike, string $className): iterable
    {
        foreach (NodeHelper::find($classLike, If_::class) as $outer) {
            $first = $this->firstMeaningful(array_values($outer->stmts));

            if (! $first instanceof If_) {
                continue;
            }

            $match = $this->sameCondition($outer->cond, $first->cond);

            if ($match === null) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $first,
                message: sprintf(
                    '%s re-tests `%s` as the first statement inside the branch that already established it.',
                    $className,
                    NodeHelper::printAny($first->cond),
                ),
                suggestion: 'Drop the inner condition, or merge the two into one condition if the outer branch has '
                    .'no other body.',
                confidence: $match === 'exact' ? 92 : 80,
                fingerprint: sprintf('%s:nested:%s', $className, NodeHelper::printAny($first->cond)),
                metrics: ['match' => $match],
            );
        }
    }

    /**
     * The same condition twice in one `if`/`elseif` chain: the second branch is
     * unreachable.
     *
     * @return iterable<Finding>
     */
    private function chainRepeats(AnalysisContext $context, ClassLike $classLike, string $className): iterable
    {
        foreach (NodeHelper::find($classLike, If_::class) as $if) {
            $seen = [NodeHelper::printAny($if->cond) => true];

            foreach ($if->elseifs as $elseif) {
                $printed = NodeHelper::printAny($elseif->cond);

                if (! isset($seen[$printed])) {
                    $seen[$printed] = true;

                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: $elseif,
                    message: sprintf(
                        '%s tests `%s` twice in the same if/elseif chain, so the later branch can never run.',
                        $className,
                        $printed,
                    ),
                    suggestion: 'Remove the duplicate branch, or correct the condition it was meant to test.',
                    confidence: 94,
                    fingerprint: sprintf('%s:chain:%s', $className, $printed),
                    metrics: ['condition' => $printed],
                );
            }
        }
    }

    /**
     * `$x !== null && $x !== null` -- one side is dead weight.
     *
     * @return iterable<Finding>
     */
    private function duplicatedOperands(AnalysisContext $context, ClassLike $classLike, string $className): iterable
    {
        foreach ([BooleanAnd::class, BooleanOr::class] as $type) {
            foreach (NodeHelper::find($classLike, $type) as $operation) {
                $left = NodeHelper::printAny($operation->left);
                $right = NodeHelper::printAny($operation->right);

                if ($left !== $right) {
                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: $operation,
                    message: sprintf(
                        '%s repeats `%s` on both sides of a boolean operator.',
                        $className,
                        $left,
                    ),
                    suggestion: 'Keep one side. If the two were meant to test different things, one of them has the '
                        .'wrong subject.',
                    confidence: 90,
                    fingerprint: sprintf('%s:operand:%s', $className, $left),
                    metrics: ['operand' => $left],
                );
            }
        }
    }

    /**
     * @return 'exact'|'equivalent'|null
     */
    private function sameCondition(Expr $outer, Expr $inner): ?string
    {
        if (NodeHelper::printAny($outer) === NodeHelper::printAny($inner)) {
            return 'exact';
        }

        $outerShape = ConditionShape::of($outer);
        $innerShape = ConditionShape::of($inner);

        if ($outerShape instanceof ConditionShape && $innerShape instanceof ConditionShape && $outerShape->equivalentTo($innerShape)) {
            return 'equivalent';
        }

        return null;
    }

    /**
     * @param  list<Stmt>  $statements
     */
    private function firstMeaningful(array $statements): ?Stmt
    {
        foreach ($statements as $statement) {
            if (! $statement instanceof Nop) {
                return $statement;
            }
        }

        return null;
    }

    /**
     * The enclosing method name, so two literal `if (true)` guards in one class
     * do not share a fingerprint.
     */
    private function scopeOf(If_ $if): string
    {
        return NodeHelper::enclosingMethod($if)?->name->toString() ?? 'closure';
    }
}
