<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;

/**
 * A simple presence check reduced to "what is being tested" and "which way".
 *
 * `$user === null`, `is_null($user)` and `! $user` all ask the same question
 * about the same thing in three different spellings. Rules that reason about
 * duplicated conditions need to see through the spelling, and more than one
 * rule needs that, so the normalisation lives here.
 */
final readonly class ConditionShape
{
    public function __construct(
        public string $subject,
        public ConditionKind $kind,
    ) {}

    /**
     * Reduce an expression to a shape, or null when it is not a simple
     * presence check on a single subject.
     */
    public static function of(Expr $expr): ?self
    {
        if ($expr instanceof BooleanNot) {
            $inner = self::of($expr->expr);

            return $inner instanceof self
                ? new self($inner->subject, $inner->kind->negated())
                : null;
        }

        if ($expr instanceof Empty_) {
            $subject = self::subjectOf($expr->expr);

            return $subject === null ? null : new self($subject, ConditionKind::IsEmpty);
        }

        if ($expr instanceof Isset_ && count($expr->vars) === 1) {
            $subject = self::subjectOf($expr->vars[0]);

            return $subject === null ? null : new self($subject, ConditionKind::NotNull);
        }

        if ($expr instanceof FuncCall && $expr->name instanceof Name && NodeHelper::baseName($expr->name->toString()) === 'is_null') {
            $args = $expr->getArgs();

            if (count($args) !== 1) {
                return null;
            }

            $subject = self::subjectOf($args[0]->value);

            return $subject === null ? null : new self($subject, ConditionKind::IsNull);
        }

        if ($expr instanceof Identical || $expr instanceof NotIdentical || $expr instanceof Equal || $expr instanceof NotEqual) {
            return self::fromComparison($expr);
        }

        $subject = self::subjectOf($expr);

        return $subject === null ? null : new self($subject, ConditionKind::Truthy);
    }

    /**
     * Whether both shapes test the same subject for the same outcome, however
     * they are written.
     */
    public function equivalentTo(self $other): bool
    {
        return $this->subject === $other->subject
            && $this->kind->family() === $other->kind->family();
    }

    public function describe(): string
    {
        return $this->kind->describe($this->subject);
    }

    private static function fromComparison(Identical|NotIdentical|Equal|NotEqual $expr): ?self
    {
        $nullOnRight = self::isNullLiteral($expr->right);
        $nullOnLeft = self::isNullLiteral($expr->left);

        if (! $nullOnRight && ! $nullOnLeft) {
            return null;
        }

        $subject = self::subjectOf($nullOnRight ? $expr->left : $expr->right);

        if ($subject === null) {
            return null;
        }

        // Loose comparison against null also matches 0, '' and [], so it is a
        // falsiness test rather than a null test.
        $kind = match (true) {
            $expr instanceof Identical => ConditionKind::IsNull,
            $expr instanceof NotIdentical => ConditionKind::NotNull,
            $expr instanceof Equal => ConditionKind::Falsy,
            default => ConditionKind::Truthy,
        };

        return new self($subject, $kind);
    }

    private static function isNullLiteral(Expr $expr): bool
    {
        return $expr instanceof ConstFetch && mb_strtolower($expr->name->toString()) === 'null';
    }

    /**
     * Only subjects with no side effects qualify: calling a method twice is not
     * the same as testing a variable twice.
     */
    private static function subjectOf(Expr $expr): ?string
    {
        if ($expr instanceof Variable || $expr instanceof PropertyFetch || $expr instanceof StaticPropertyFetch || $expr instanceof ArrayDimFetch) {
            return NodeHelper::printAny($expr);
        }

        return null;
    }
}
