<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;

/**
 * SL205 -- filtering in PHP what the database could have filtered.
 *
 * `User::all()->where('active', true)` loads every row into memory and then
 * discards most of them. The equivalent query does the same work in the engine
 * that is built for it.
 */
final class CollectionInsteadOfQueryRule extends BaseRule
{
    /**
     * Collection methods with a direct query builder equivalent, mapped to
     * what that equivalent is, and how sure we are the swap is right.
     *
     * @var array<string, array{suggestion: string, confidence: int}>
     */
    private const array REPLACEABLE = [
        'where' => ['suggestion' => 'where(...)->get()', 'confidence' => 88],
        'wherein' => ['suggestion' => 'whereIn(...)->get()', 'confidence' => 88],
        'wherenotin' => ['suggestion' => 'whereNotIn(...)->get()', 'confidence' => 88],
        'firstwhere' => ['suggestion' => 'where(...)->first()', 'confidence' => 90],
        'count' => ['suggestion' => 'count()', 'confidence' => 92],
        'sum' => ['suggestion' => 'sum(...)', 'confidence' => 88],
        'avg' => ['suggestion' => 'avg(...)', 'confidence' => 88],
        'max' => ['suggestion' => 'max(...)', 'confidence' => 88],
        'min' => ['suggestion' => 'min(...)', 'confidence' => 88],
        'pluck' => ['suggestion' => 'pluck(...)', 'confidence' => 82],
        'sortby' => ['suggestion' => 'orderBy(...)->get()', 'confidence' => 84],
        'sortbydesc' => ['suggestion' => 'orderByDesc(...)->get()', 'confidence' => 84],
        'take' => ['suggestion' => 'limit(...)->get()', 'confidence' => 84],
        'first' => ['suggestion' => 'first()', 'confidence' => 86],
        'contains' => ['suggestion' => 'where(...)->exists()', 'confidence' => 80],
        // A closure predicate has no direct SQL equivalent, so this one is a
        // hint rather than a recommendation.
        'filter' => ['suggestion' => 'a where(...) clause, if the predicate can be expressed in SQL', 'confidence' => 64],
        'reject' => ['suggestion' => 'a whereNot(...) clause, if the predicate can be expressed in SQL', 'confidence' => 64],
    ];

    public function id(): string
    {
        return 'SL205';
    }

    public function name(): string
    {
        return 'Collection Instead Of Database Query';
    }

    public function description(): string
    {
        return 'Flags a full table load followed immediately by a collection operation the database could have performed.';
    }

    public function explanation(): string
    {
        return 'Loading every row to filter, count or sort it in PHP uses memory proportional to the table and '
            .'throws most of the work away. Collection operations are not the problem -- doing them to rows that '
            .'were fetched only to be discarded is.';
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
        foreach ($context->classLikes() as $classLike) {
            $className = NodeHelper::shortName($classLike) ?? 'anonymous class';

            foreach (NodeHelper::find($classLike, MethodCall::class) as $call) {
                $inner = $call->var;

                if (! $inner instanceof StaticCall) {
                    continue;
                }

                $loader = NodeHelper::callName($inner);

                if (! NodeHelper::isNameOneOf($loader, ['all', 'get'])) {
                    continue;
                }

                // `SomeHelper::all()` on a class we can see is not a model has
                // no query to push the filtering into.
                if (! LaravelCalls::targetsDatabase($inner, $context->index)) {
                    continue;
                }

                $operation = NodeHelper::callName($call);

                if ($operation === null) {
                    continue;
                }

                $replacement = self::REPLACEABLE[mb_strtolower($operation)] ?? null;

                if ($replacement === null) {
                    continue;
                }

                $model = NodeHelper::baseName(NodeHelper::staticCallClass($inner) ?? 'Model');
                $method = NodeHelper::enclosingMethod($call);
                $methodName = $method?->name->toString() ?? 'closure';

                yield $this->report(
                    context: $context,
                    at: $call,
                    message: sprintf(
                        '%s::%s() loads every %s row with %s::%s() and then filters it in PHP with ->%s().',
                        $className,
                        $methodName,
                        $model,
                        $model,
                        $loader,
                        $operation,
                    ),
                    suggestion: sprintf('Let the database do it: %s::%s.', $model, $replacement['suggestion']),
                    confidence: $replacement['confidence'],
                    fingerprint: sprintf('%s::%s:%s::%s->%s', $className, $methodName, $model, $loader, $operation),
                    metrics: [
                        'model' => $model,
                        'loader' => (string) $loader,
                        'operation' => $operation,
                    ],
                );
            }
        }
    }
}
