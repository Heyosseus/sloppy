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
use PhpParser\Node\Expr\Ternary;

/**
 * SL114 -- a ternary whose condition or either branch is itself a ternary.
 *
 * Only directly nested ternaries are flagged; a ternary nested inside a
 * function call argument is not reported. Chains are reported once, at the
 * outermost ternary.
 */
final class NestedTernaryRule extends BaseRule
{
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
        return Severity::Low;
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

                if (! ($ternary->cond instanceof Ternary || $ternary->if instanceof Ternary || $ternary->else instanceof Ternary)) {
                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: $ternary,
                    message: sprintf('%s nests a ternary inside another ternary.', $unit->subject()),
                    suggestion: 'Replace the pair with an if/elseif chain or a match expression, so each condition '
                        .'reads as its own line.',
                    confidence: 90,
                    fingerprint: sprintf('%s:%s', $unit->label(), NodeHelper::printAny($ternary)),
                    metrics: [],
                );
            }
        }
    }
}
