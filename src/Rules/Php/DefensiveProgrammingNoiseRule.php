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
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;

/**
 * SL110 -- the same guard written twice in one method.
 *
 * Two spellings of one check with one outcome -- `if (! $user) return null;`
 * followed by `if ($user === null) return null;` -- is noise, not safety. The
 * rule only reports pairs where the subject is provably untouched in between,
 * because a reassignment makes the second check meaningful.
 */
final class DefensiveProgrammingNoiseRule extends BaseRule
{
    public function id(): string
    {
        return 'SL110';
    }

    public function name(): string
    {
        return 'Defensive Programming Noise';
    }

    public function description(): string
    {
        return 'Flags a method that guards the same subject the same way twice, with the same outcome and no reassignment in between.';
    }

    public function explanation(): string
    {
        return 'A guard repeated in different words reads as though it protects against something new, so the next '
            .'reader spends time working out the difference and finds none. It also suggests the two checks were '
            .'written at different times without either author reading the other.';
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

            foreach (NodeHelper::methods($classLike) as $method) {
                if ($method->stmts === null) {
                    continue;
                }

                yield from $this->duplicateGuards($context, $method, $className);
            }
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function duplicateGuards(AnalysisContext $context, ClassMethod $method, string $className): iterable
    {
        /** @var list<array{node: If_, shape: ConditionShape, outcome: string}> $guards */
        $guards = [];

        foreach (NodeHelper::find($method, If_::class) as $if) {
            $outcome = $this->earlyExitOf($if);

            if ($outcome === null) {
                continue;
            }

            $shape = ConditionShape::of($if->cond);

            if (! $shape instanceof ConditionShape) {
                continue;
            }

            $guards[] = ['node' => $if, 'shape' => $shape, 'outcome' => $outcome];
        }

        $reported = [];

        foreach ($guards as $index => $guard) {
            foreach (array_slice($guards, 0, $index) as $earlier) {
                if (! $guard['shape']->equivalentTo($earlier['shape'])) {
                    continue;
                }

                if ($guard['outcome'] !== $earlier['outcome']) {
                    continue;
                }

                if ($this->reassignedBetween($method, $guard['shape']->subject, $earlier['node'], $guard['node'])) {
                    continue;
                }

                $key = $guard['shape']->subject.'|'.$guard['outcome'];

                if (isset($reported[$key])) {
                    continue;
                }

                $reported[$key] = true;

                yield $this->report(
                    context: $context,
                    at: $guard['node'],
                    message: sprintf(
                        '%s::%s() already guarded that %s on line %d, with the same outcome.',
                        $className,
                        $method->name->toString(),
                        $earlier['shape']->describe(),
                        $earlier['node']->getStartLine(),
                    ),
                    suggestion: 'Keep one guard. If the two were meant to catch different states -- null versus '
                        .'empty, say -- make that difference explicit in the conditions and in what each returns.',
                    confidence: 78,
                    fingerprint: sprintf('%s::%s:%s', $className, $method->name->toString(), $guard['shape']->subject),
                    metrics: [
                        'subject' => $guard['shape']->subject,
                        'first_check' => $earlier['shape']->kind->value,
                        'second_check' => $guard['shape']->kind->value,
                        'outcome' => $guard['outcome'],
                    ],
                );
            }
        }
    }

    /**
     * The normalised outcome of a guard, or null when the `if` is not a guard:
     * it must have no else branches and a body that only leaves.
     */
    private function earlyExitOf(If_ $if): ?string
    {
        if ($if->elseifs !== [] || $if->else instanceof Stmt\Else_) {
            return null;
        }

        $statements = array_values(array_filter(
            $if->stmts,
            static fn (Stmt $statement): bool => ! $statement instanceof Nop,
        ));

        if (count($statements) !== 1) {
            return null;
        }

        $statement = $statements[0];

        if ($statement instanceof Return_ || $statement instanceof Continue_ || $statement instanceof Break_) {
            return NodeHelper::printAny($statement);
        }

        if ($statement instanceof Expression && $statement->expr instanceof Throw_) {
            return NodeHelper::printAny($statement);
        }

        return null;
    }

    /**
     * Whether the guarded subject is written to between the two guards, which
     * would make the second check meaningful.
     */
    private function reassignedBetween(ClassMethod $method, string $subject, If_ $first, If_ $second): bool
    {
        $from = $first->getEndLine();
        $to = $second->getStartLine();

        foreach (NodeHelper::find($method, Assign::class) as $assign) {
            $line = $assign->getStartLine();

            if ($line < $from || $line > $to) {
                continue;
            }

            if (NodeHelper::printAny($assign->var) === $subject) {
                return true;
            }
        }

        return false;
    }
}
