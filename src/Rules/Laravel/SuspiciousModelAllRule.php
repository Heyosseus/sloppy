<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;

/**
 * SL210 -- an unbounded table load that the surrounding code then walks.
 *
 * `Model::all()` is fine for a lookup table of a dozen rows, so this rule does
 * not flag it on sight. It fires when there is contextual evidence that the
 * result is being processed row by row, or when the load itself sits inside a
 * loop -- the cases where growth in the table turns into memory pressure.
 */
final class SuspiciousModelAllRule extends BaseRule
{
    public function id(): string
    {
        return 'SL210';
    }

    public function name(): string
    {
        return 'Suspicious Model::all()';
    }

    public function description(): string
    {
        return 'Flags Model::all() whose result is iterated in the same method, or which is called from inside a loop.';
    }

    public function explanation(): string
    {
        return 'A full table load uses memory proportional to the table, which is invisible in development and '
            .'fatal once the table grows. Where every row genuinely is needed, chunking or a lazy cursor gives the '
            .'same result with bounded memory. Small reference tables are exempt via the ignore_models option.';
    }

    public function category(): Category
    {
        return Category::Performance;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $ignored = $this->listOption('ignore_models', [
            'Country', 'Currency', 'Setting', 'Role', 'Permission', 'Language', 'Timezone', 'State', 'Locale',
        ]);

        foreach ($context->classLikes() as $classLike) {
            $className = NodeHelper::shortName($classLike) ?? 'anonymous class';

            foreach (NodeHelper::find($classLike, StaticCall::class) as $call) {
                if (NodeHelper::callName($call) !== 'all' || ! LaravelCalls::targetsDatabase($call, $context->index)) {
                    continue;
                }

                $model = NodeHelper::baseName(NodeHelper::staticCallClass($call) ?? '');

                if ($model === '' || NodeHelper::isNameOneOf($model, $ignored)) {
                    continue;
                }

                // A collection operation chained straight onto all() is
                // SL205's finding, not this rule's.
                $parent = $call->getAttribute('parent');

                if ($parent instanceof MethodCall && $parent->var === $call) {
                    continue;
                }

                $method = NodeHelper::enclosingMethod($call);
                $reason = $this->reasonFor($call, $method);

                if ($reason === null) {
                    continue;
                }

                $methodName = $method?->name->toString() ?? 'closure';

                yield $this->report(
                    context: $context,
                    at: $call,
                    message: sprintf(
                        '%s::%s() loads every %s row with %s::all() and %s.',
                        $className,
                        $methodName,
                        $model,
                        $model,
                        $reason['description'],
                    ),
                    suggestion: sprintf(
                        'Narrow the query to the rows actually needed, or process them in bounded batches: '
                        .'%s::query()->chunkById(500, function ($rows) { ... }) or ->lazy(). If the whole table '
                        .'really is small and always will be, add %s to sloppy.rules.SL210.ignore_models.',
                        $model,
                        $model,
                    ),
                    confidence: $reason['confidence'],
                    fingerprint: sprintf('%s::%s:%s', $className, $methodName, $model),
                    metrics: [
                        'model' => $model,
                        'evidence' => $reason['evidence'],
                    ],
                );
            }
        }
    }

    /**
     * Contextual evidence that the full load matters here, or null when there
     * is none and the rule should stay quiet.
     *
     * @return array{description: string, confidence: int, evidence: string}|null
     */
    private function reasonFor(StaticCall $call, ?ClassMethod $method): ?array
    {
        if (NodeHelper::isInsideLoop($call)) {
            return [
                'description' => 'does so inside a loop, reloading the table on every iteration',
                'confidence' => 88,
                'evidence' => 'inside-loop',
            ];
        }

        if ($method instanceof ClassMethod && $this->isIteratedIn($call, $method)) {
            return [
                'description' => 'then walks the result row by row',
                'confidence' => 70,
                'evidence' => 'iterated',
            ];
        }

        return null;
    }

    /**
     * Whether the loaded collection is iterated in the same method, either
     * directly or through the variable it was assigned to.
     */
    private function isIteratedIn(StaticCall $call, ClassMethod $method): bool
    {
        $variable = $this->assignedVariable($call);

        foreach (NodeHelper::find($method, Foreach_::class) as $loop) {
            if ($loop->expr === $call) {
                return true;
            }

            if ($variable !== null && $loop->expr instanceof Variable && $loop->expr->name === $variable) {
                return true;
            }
        }

        return false;
    }

    /**
     * The variable name `all()` was assigned to, if it was.
     */
    private function assignedVariable(StaticCall $call): ?string
    {
        $parent = $call->getAttribute('parent');

        if (! $parent instanceof Node) {
            return null;
        }

        $assign = $parent instanceof Assign ? $parent : NodeHelper::closestAncestor($call, Assign::class);

        if (! $assign instanceof Assign || ! $this->isRootOf($assign->expr, $call)) {
            return null;
        }

        return $assign->var instanceof Variable && is_string($assign->var->name)
            ? $assign->var->name
            : null;
    }

    private function isRootOf(Expr $expr, StaticCall $call): bool
    {
        return NodeHelper::chainRoot($expr) === $call || $expr === $call;
    }
}
