<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\CodeUnit;
use Heyosseus\Sloppy\Ast\ConditionShape;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;

/**
 * SL110 -- the same guard written twice in one method.
 *
 * Two spellings of one check with one outcome -- `if (! $user) return null;`
 * followed by `if ($user === null) return null;` -- is noise, not safety. The
 * rule only reports pairs where the first guard has certainly run before the
 * second, the second can only pass where the first would have, and the
 * subject is provably untouched in between, because a reassignment makes the
 * second check meaningful.
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
        return 'Flags a method or function that guards the same subject twice, where the second guard can only catch what the first already did, with the same outcome and no reassignment in between.';
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
        foreach (CodeUnit::inFile($context->file) as $unit) {
            if ($unit->hasBody()) {
                yield from $this->duplicateGuards($context, $unit);
            }
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function duplicateGuards(AnalysisContext $context, CodeUnit $unit): iterable
    {
        /** @var list<array{node: If_, shape: ConditionShape, outcome: string}> $guards */
        $guards = [];
        $root = $unit->root();

        foreach (NodeHelper::findOwn($root, If_::class) as $if) {
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

        if (count($guards) < 2) {
            return;
        }

        $writes = $this->writes($root);
        $reported = [];

        foreach ($guards as $index => $guard) {
            foreach (array_slice($guards, 0, $index) as $earlier) {
                // Past the first guard its condition is known to be false, so
                // the second is redundant only when it cannot pass without the
                // first having passed: `! $x` after `$x === null` still
                // catches `0`, `''` and `[]`.
                if (! $guard['shape']->implies($earlier['shape'])) {
                    continue;
                }

                if ($guard['outcome'] !== $earlier['outcome']) {
                    continue;
                }

                if (! $this->governs($earlier['node'], $guard['node'])) {
                    continue;
                }

                if ($this->reassignedBetween($writes, $guard['shape']->subject, $earlier['node'], $guard['node'])) {
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
                        '%s already guarded that %s on line %d, with the same outcome.',
                        $unit->subject(),
                        $earlier['shape']->describe(),
                        $earlier['node']->getStartLine(),
                    ),
                    suggestion: 'Keep one guard. If the two were meant to catch different states -- null versus '
                        .'empty, say -- make that difference explicit in the conditions and in what each returns.',
                    confidence: 78,
                    fingerprint: sprintf('%s:%s', $unit->label(), $guard['shape']->subject),
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
     * Whether the first guard has certainly run, in the same scope, by the
     * time the second is reached: it sits earlier in a block that encloses
     * the second, with no closure in between. Two loops one after the other,
     * each skipping a null `$item`, guard two different variables.
     */
    private function governs(If_ $first, If_ $second): bool
    {
        $block = $first->getAttribute('parent');

        if (! $block instanceof Node || $second->getStartFilePos() <= $first->getEndFilePos()) {
            return false;
        }

        foreach (NodeHelper::ancestors($second) as $ancestor) {
            if ($ancestor === $block) {
                return true;
            }

            if ($ancestor instanceof FunctionLike) {
                return false;
            }
        }

        return false;
    }

    /**
     * Every expression the code writes to, with where the write happens:
     * assignments of every kind, destructuring, `foreach` keys and values,
     * increments, and `catch` variables.
     *
     * @param  Node|list<Stmt>  $root
     * @return list<array{target: string, at: int}>
     */
    private function writes(Node|array $root): array
    {
        $writes = [];

        foreach (NodeHelper::findOwn($root, Expr::class) as $expr) {
            $target = match (true) {
                $expr instanceof Assign, $expr instanceof AssignRef, $expr instanceof AssignOp => $expr->var,
                $expr instanceof PreInc, $expr instanceof PreDec, $expr instanceof PostInc, $expr instanceof PostDec => $expr->var,
                default => null,
            };

            foreach ($target instanceof Expr ? $this->targetsOf($target) : [] as $written) {
                $writes[] = ['target' => NodeHelper::printAny($written), 'at' => $expr->getStartFilePos()];
            }
        }

        foreach (NodeHelper::findOwn($root, Foreach_::class) as $loop) {
            foreach ([$loop->keyVar, $loop->valueVar] as $variable) {
                foreach ($variable instanceof Expr ? $this->targetsOf($variable) : [] as $written) {
                    $writes[] = ['target' => NodeHelper::printAny($written), 'at' => $written->getStartFilePos()];
                }
            }
        }

        foreach (NodeHelper::findOwn($root, Catch_::class) as $catch) {
            if ($catch->var instanceof Variable) {
                $writes[] = ['target' => NodeHelper::printAny($catch->var), 'at' => $catch->var->getStartFilePos()];
            }
        }

        return $writes;
    }

    /**
     * The variables a write target names: itself, or every element of a
     * `list()` / `[...]` destructuring, however deeply nested.
     *
     * @return list<Expr>
     */
    private function targetsOf(Expr $target): array
    {
        if (! $target instanceof List_ && ! $target instanceof Array_) {
            return [$target];
        }

        $targets = [];

        foreach ($target->items as $item) {
            if ($item instanceof ArrayItem) {
                $targets = [...$targets, ...$this->targetsOf($item->value)];
            }
        }

        return $targets;
    }

    /**
     * Whether the guarded subject -- or what it is read from, `$order` for
     * `$order->user` -- is written to between the two guards, which would
     * make the second check meaningful.
     *
     * @param  list<array{target: string, at: int}>  $writes
     */
    private function reassignedBetween(array $writes, string $subject, If_ $first, If_ $second): bool
    {
        $from = $first->getEndFilePos();
        $to = $second->getStartFilePos();

        foreach ($writes as $write) {
            if ($write['at'] < $from || $write['at'] > $to) {
                continue;
            }

            $target = $write['target'];

            if ($subject === $target || str_starts_with($subject, $target.'[') || str_starts_with($subject, $target.'->')) {
                return true;
            }
        }

        return false;
    }
}
