<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\SourceKind;
use Heyosseus\Sloppy\Ast\CodeUnit;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Scalar;

/**
 * SL114 -- a ternary whose condition or either branch is itself a ternary.
 *
 * Only directly nested ternaries are flagged; a ternary nested inside a
 * function call argument is not reported. Chains are reported once, at the
 * outermost ternary. A chain made only of short ternaries (`$a ?: $b ?: $c`)
 * is a list of fallbacks rather than a nested decision, and is not reported.
 */
final class NestedTernaryRule extends BaseRule
{
    /** A chain shallower than this is a single nested pair, reported at Low. */
    private const int DEEP_CHAIN = 3;

    public function id(): string
    {
        return 'SL114';
    }

    public function name(): string
    {
        return 'Nested Ternary';
    }

    public function description(): string
    {
        return 'Flags a ternary expression whose condition or either branch is itself a ternary.';
    }

    public function explanation(): string
    {
        return 'A ternary nested inside another one forces the reader to parse two decisions to find the value of '
            .'one expression, usually without the parentheses that would make the grouping obvious. An if/elseif '
            .'chain, or a match expression, states the same branches as separate statements the reader can scan '
            .'top to bottom.';
    }

    public function category(): Category
    {
        return Category::Readability;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function sourceKinds(): array
    {
        return SourceKind::cases();
    }

    public function analyze(AnalysisContext $context): iterable
    {
        foreach (CodeUnit::inFile($context->file, withTopLevel: true) as $unit) {
            if (! $unit->hasBody()) {
                continue;
            }

            foreach (NodeHelper::findOwn($unit->root(), Ternary::class) as $ternary) {
                if ($ternary->getAttribute('parent') instanceof Ternary) {
                    continue;
                }

                $where = $this->nestedIn($ternary);

                if ($where === null) {
                    continue;
                }

                $chain = $this->chain($ternary);

                if ($this->onlyFallbacks($chain)) {
                    continue;
                }

                $depth = $this->depth($ternary);

                yield $this->report(
                    context: $context,
                    at: $ternary,
                    message: sprintf(
                        '%s nests a ternary %s of another ternary%s.',
                        $unit->subject(),
                        $where,
                        $depth >= self::DEEP_CHAIN ? sprintf(', %d levels deep', $depth) : '',
                    ),
                    suggestion: $this->suggestion($chain),
                    confidence: $ternary->cond instanceof Ternary ? 95 : 90,
                    fingerprint: sprintf('%s:%s', $unit->label(), NodeHelper::printAny($ternary)),
                    metrics: ['depth' => $depth],
                    severity: $depth >= self::DEEP_CHAIN ? null : Severity::Low,
                );
            }
        }
    }

    /**
     * Where the first directly nested ternary sits. The condition is named
     * first because a decision hidden inside another decision's test is the
     * hardest of the three to read.
     */
    private function nestedIn(Ternary $ternary): ?string
    {
        return match (true) {
            $ternary->cond instanceof Ternary => 'in the condition',
            $ternary->if instanceof Ternary => 'in the true branch',
            $ternary->else instanceof Ternary => 'in the false branch',
            default => null,
        };
    }

    /**
     * Every ternary in the chain, outermost first.
     *
     * @return list<Ternary>
     */
    private function chain(Ternary $ternary): array
    {
        $chain = [$ternary];

        foreach ([$ternary->cond, $ternary->if, $ternary->else] as $part) {
            if ($part instanceof Ternary) {
                $chain = [...$chain, ...$this->chain($part)];
            }
        }

        return $chain;
    }

    private function depth(Ternary $ternary): int
    {
        $deepest = 0;

        foreach ([$ternary->cond, $ternary->if, $ternary->else] as $part) {
            if ($part instanceof Ternary) {
                $deepest = max($deepest, $this->depth($part));
            }
        }

        return $deepest + 1;
    }

    /**
     * `$a ?: $b ?: $c` takes the first value that is set, the way `??` does.
     *
     * @param  list<Ternary>  $chain
     */
    private function onlyFallbacks(array $chain): bool
    {
        foreach ($chain as $ternary) {
            if ($ternary->if !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<Ternary>  $chain
     */
    private function suggestion(array $chain): string
    {
        $subject = $this->sharedSubject($chain);

        if ($subject !== null) {
            return sprintf(
                'Every condition compares %s with a value: replace the chain with match (%s), one arm per value.',
                $subject,
                $subject,
            );
        }

        return 'Replace the chain with match (true) or an if/elseif chain, so each condition reads as its own line.';
    }

    /**
     * The expression every condition in the chain compares with a value, as
     * in `$s === 'a' ? ... : ($s === 'b' ? ... : ...)`, or null when the
     * conditions test different things.
     *
     * @param  list<Ternary>  $chain
     */
    private function sharedSubject(array $chain): ?string
    {
        $subject = null;

        foreach ($chain as $ternary) {
            $compared = $this->comparedWithValue($ternary);

            if ($compared === null || ($subject !== null && $compared !== $subject)) {
                return null;
            }

            $subject = $compared;
        }

        return $subject;
    }

    private function comparedWithValue(Ternary $ternary): ?string
    {
        $condition = $ternary->cond;

        if (! $ternary->if instanceof Expr || ! $condition instanceof Identical && ! $condition instanceof Equal) {
            return null;
        }

        return match (true) {
            $this->isValue($condition->right) && ! $this->isValue($condition->left) => NodeHelper::printAny($condition->left),
            $this->isValue($condition->left) && ! $this->isValue($condition->right) => NodeHelper::printAny($condition->right),
            default => null,
        };
    }

    private function isValue(Expr $expression): bool
    {
        return $expression instanceof Scalar
            || $expression instanceof ConstFetch
            || $expression instanceof ClassConstFetch
            || ($expression instanceof UnaryMinus && $expression->expr instanceof Scalar);
    }
}
