<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\LaravelRule;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;

/**
 * SL203 -- relationship access inside a loop, which usually means one query
 * per iteration.
 *
 * The word "possible" is load-bearing. Static analysis cannot see whether the
 * collection was hydrated with its relations somewhere else, so the rule looks
 * for eager loading on the expression the loop iterates and steps back when it
 * finds it. When it cannot tell, it says so and reports lower confidence.
 */
final class PossibleNPlusOneRule extends LaravelRule
{
    /**
     * Methods that only a query builder has, so finding one in the expression
     * a loop iterates means the loop is walking database rows.
     *
     * @var list<string>
     */
    private const array QUERY_EVIDENCE = [
        'where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereHas', 'whereDoesntHave',
        'orWhere', 'with', 'withCount', 'withSum', 'orderBy', 'orderByDesc', 'latest', 'oldest',
        'paginate', 'simplePaginate', 'cursorPaginate', 'lazy', 'chunk', 'cursor',
        'newQuery', 'query', 'firstWhere', 'has', 'join', 'leftJoin',
    ];

    /**
     * Collection and query methods that trigger a fetch when called on a
     * relation.
     *
     * @var list<string>
     */
    private const array TRIGGERING = [
        'count', 'sum', 'avg', 'average', 'min', 'max', 'exists', 'doesntExist',
        'get', 'first', 'firstOrFail', 'pluck', 'value', 'find', 'contains', 'load',
        'loadMissing', 'toArray', 'isEmpty', 'isNotEmpty', 'paginate',
    ];

    /**
     * Collection methods that shape a collection rather than name a relation.
     * Together with the triggering and query method lists, they tell
     * `$group->pluck('id')->all()` on a grouped collection apart from
     * `$order->items()->count()` on a model.
     *
     * @var list<string>
     */
    private const array COLLECTION_METHODS = [
        'all', 'map', 'mapWithKeys', 'flatMap', 'filter', 'reject', 'each', 'groupBy', 'keyBy',
        'sortBy', 'sortByDesc', 'unique', 'values', 'keys', 'only', 'except', 'flatten', 'collapse',
        'take', 'skip', 'slice', 'chunk', 'merge', 'reverse', 'whereStrict',
    ];

    public function id(): string
    {
        return 'SL203';
    }

    public function name(): string
    {
        return 'Possible N+1';
    }

    public function description(): string
    {
        return 'Flags relationship access inside a loop where the iterated collection does not appear to eager load that relation.';
    }

    public function explanation(): string
    {
        return 'Reading a relationship on each item of a collection issues one query per item, so a page that '
            .'looks fine with ten rows falls over with ten thousand. This is reported as possible rather than '
            .'certain: the relation may have been eager loaded somewhere this rule cannot see, in which case the '
            .'access is free.';
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
        $ignored = $this->listOption('ignore_relations', ['pivot', 'attributes', 'relations']);

        foreach ($context->classLikes() as $classLike) {
            $className = NodeHelper::shortName($classLike) ?? 'anonymous class';

            foreach (NodeHelper::methods($classLike) as $method) {
                if ($method->stmts === null) {
                    continue;
                }

                yield from $this->inspect($context, $classLike, $method, $className, $ignored);
            }
        }
    }

    /**
     * @param  list<string>  $ignored
     * @return iterable<Finding>
     */
    private function inspect(AnalysisContext $context, ClassLike $classLike, ClassMethod $method, string $className, array $ignored): iterable
    {
        $assignments = NodeHelper::assignedVariables($method);

        foreach (NodeHelper::find($method, Foreach_::class) as $loop) {
            if (! $loop->valueVar instanceof Variable || ! is_string($loop->valueVar->name)) {
                continue;
            }

            $item = $loop->valueVar->name;
            $source = $this->resolveSource($loop->expr, $assignments);

            // Iterating a literal array or a range cannot produce queries.
            if ($source instanceof Array_) {
                continue;
            }

            $eager = $source instanceof Expr ? $this->eagerLoaded($source) : [];
            $fromAll = $source instanceof Expr && $this->isModelAll($source);

            // Whether the thing being iterated is recognisably database-backed.
            // When it is not, `$node->child->name` is just as likely to be a
            // plain object graph as a relation, so only accesses that are
            // unmistakably Eloquent are reported.
            $databaseBacked = $this->isDatabaseBacked($context, $loop, $classLike, $method, $source);

            /** @var array<string, true> $reported */
            $reported = [];

            foreach ($this->accesses($loop, $item) as $access) {
                $relation = $access['relation'];

                if (NodeHelper::isNameOneOf($relation, $ignored) || isset($reported[$relation])) {
                    continue;
                }

                if (! $databaseBacked && ! $access['triggering']) {
                    continue;
                }

                if ($this->isEagerLoaded($relation, $eager)) {
                    continue;
                }

                $reported[$relation] = true;

                yield $this->report(
                    context: $context,
                    at: $access['node'],
                    message: sprintf(
                        '%s::%s() reads $%s->%s inside a loop%s.',
                        $className,
                        $method->name->toString(),
                        $item,
                        $relation,
                        $eager === []
                            ? ' over a collection with no visible eager loading'
                            : ' and the collection eager loads only '.implode(', ', $eager),
                    ),
                    suggestion: sprintf(
                        'Eager load the relation when the collection is built -- ->with(\'%s\') -- or call '
                        .'->load(\'%s\') on the collection once before the loop. If the relation is only counted, '
                        .'->withCount(\'%s\') avoids loading the rows at all.',
                        $relation,
                        $relation,
                        $relation,
                    ),
                    confidence: $this->confidenceFrom(62, [
                        $eager === [],
                        $fromAll,
                        $access['triggering'],
                    ], 8, 86),
                    fingerprint: sprintf('%s::%s:$%s->%s', $className, $method->name->toString(), $item, $relation),
                    metrics: [
                        'relation' => $relation,
                        'eager_loaded' => implode(', ', $eager),
                        'source_is_all' => $fromAll,
                    ],
                );
            }
        }
    }

    /**
     * Whether the loop is walking rows that came from the database.
     *
     * The bar is deliberately high, because `$node->child->name` is a
     * relationship read in a Laravel app and an object-graph walk everywhere
     * else. Four things clear it: a chain rooted in a model or the DB facade,
     * a chain using a method only a query builder has, a parameter type-hinted
     * as a collection, and a relation on the Eloquent model being analysed.
     */
    private function isDatabaseBacked(AnalysisContext $context, Foreach_ $loop, ClassLike $classLike, ClassMethod $method, ?Expr $source): bool
    {
        if ($source instanceof Expr) {
            $root = NodeHelper::chainRoot($source);

            if ($root instanceof StaticCall && LaravelCalls::targetsDatabase($root, $context->index)) {
                return true;
            }

            // Generic names like find(), get() and count() prove nothing on
            // their own -- plenty of classes have them -- so only distinctly
            // query-builder methods count as evidence.
            foreach (NodeHelper::chainMethodNames($source) as $name) {
                if (NodeHelper::isNameOneOf($name, self::QUERY_EVIDENCE)) {
                    return true;
                }
            }
        }

        if ($loop->expr instanceof Variable && is_string($loop->expr->name) && $this->isCollectionParameter($method, $loop->expr->name)) {
            return true;
        }

        // `foreach ($this->items as $item)` inside a model walks a relation;
        // the same line in a service walks whatever that property holds.
        return NodeHelper::isEloquentModel($classLike)
            && $loop->expr instanceof PropertyFetch
            && $loop->expr->var instanceof Variable
            && $loop->expr->var->name === 'this';
    }

    /**
     * Whether a method parameter is declared as an Eloquent collection or a
     * paginator, which is as close to a type as this analyser gets.
     */
    private function isCollectionParameter(ClassMethod $method, string $name): bool
    {
        foreach ($method->params as $param) {
            if (! $param->var instanceof Variable || $param->var->name !== $name) {
                continue;
            }

            $type = NodeHelper::typeToString($param->type);

            if ($type === null) {
                return false;
            }

            return preg_match('/(Collection|Paginator|LazyCollection|Enumerable)/', $type) === 1;
        }

        return false;
    }

    /**
     * Relationship reads on the loop variable inside the loop body.
     *
     * @return list<array{node: Node, relation: string, triggering: bool}>
     */
    private function accesses(Foreach_ $loop, string $item): array
    {
        $accesses = [];

        // $item->relation->attribute -- a two-level property chain means the
        // first level had to be hydrated.
        foreach (NodeHelper::find(array_values($loop->stmts), PropertyFetch::class) as $fetch) {
            if (! $fetch->var instanceof PropertyFetch || ! $fetch->var->name instanceof Identifier) {
                continue;
            }

            if (! $this->isLoopVariable($fetch->var->var, $item)) {
                continue;
            }

            $accesses[] = [
                'node' => $fetch,
                'relation' => $fetch->var->name->toString(),
                'triggering' => false,
            ];
        }

        foreach (NodeHelper::find(array_values($loop->stmts), MethodCall::class) as $call) {
            $name = NodeHelper::callName($call);

            if ($name === null) {
                continue;
            }

            // $item->relation()->count() and $item->relation->count()
            $inner = $call->var;
            $triggering = NodeHelper::isNameOneOf($name, self::TRIGGERING)
                || NodeHelper::isNameOneOf($name, LaravelCalls::QUERY_TERMINALS);

            if ($inner instanceof MethodCall && $inner->name instanceof Identifier && $this->isLoopVariable($inner->var, $item)) {
                if (($triggering || LaravelCalls::looksLikeQuery($call)) && ! $this->isCollectionMethod($inner->name->toString())) {
                    $accesses[] = [
                        'node' => $call,
                        'relation' => $inner->name->toString(),
                        'triggering' => true,
                    ];
                }

                continue;
            }

            if ($inner instanceof PropertyFetch && $inner->name instanceof Identifier && $this->isLoopVariable($inner->var, $item) && $triggering) {
                $accesses[] = [
                    'node' => $call,
                    'relation' => $inner->name->toString(),
                    'triggering' => true,
                ];
            }
        }

        return $accesses;
    }

    /**
     * Whether a method called on the loop variable belongs to Collection or
     * Builder, in which case the loop variable is a collection and the name
     * is not a relation.
     */
    private function isCollectionMethod(string $name): bool
    {
        return NodeHelper::isNameOneOf($name, self::COLLECTION_METHODS)
            || NodeHelper::isNameOneOf($name, self::TRIGGERING)
            || NodeHelper::isNameOneOf($name, self::QUERY_EVIDENCE)
            || NodeHelper::isNameOneOf($name, LaravelCalls::QUERY_TERMINALS);
    }

    private function isLoopVariable(Expr $expr, string $item): bool
    {
        return $expr instanceof Variable && $expr->name === $item;
    }

    /**
     * Follow a loop's iterable back to the expression that produced it, one
     * assignment deep.
     *
     * @param  array<string, Expr>  $assignments
     */
    private function resolveSource(Expr $expr, array $assignments): ?Expr
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            return $assignments[$expr->name] ?? null;
        }

        return $expr;
    }

    /**
     * Relations named in `with()`, `withCount()` or `load()` on the source
     * expression.
     *
     * @return list<string>
     */
    private function eagerLoaded(Expr $source): array
    {
        $relations = [];

        foreach ([MethodCall::class, StaticCall::class] as $type) {
            foreach (NodeHelper::find($source, $type) as $call) {
                if (! NodeHelper::isNameOneOf(NodeHelper::callName($call), ['with', 'withCount', 'load', 'loadMissing', 'loadCount'])) {
                    continue;
                }

                foreach ($call->getArgs() as $arg) {
                    foreach ($this->relationNames($arg->value) as $relation) {
                        $relations[] = $relation;
                    }
                }
            }
        }

        return array_values(array_unique($relations));
    }

    /**
     * @return list<string>
     */
    private function relationNames(Expr $expr): array
    {
        if ($expr instanceof String_) {
            return [$expr->value];
        }

        if (! $expr instanceof Array_) {
            return [];
        }

        $names = [];

        foreach ($expr->items as $item) {
            // ->with(['user' => fn ($q) => ...]) names the relation in the key.
            if ($item->key instanceof String_) {
                $names[] = $item->key->value;

                continue;
            }

            if ($item->value instanceof String_) {
                $names[] = $item->value->value;
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $eager
     */
    private function isEagerLoaded(string $relation, array $eager): bool
    {
        foreach ($eager as $loaded) {
            if ($loaded === $relation) {
                return true;
            }

            // `with('items.product')` also loads `items`.
            if (str_starts_with($loaded, $relation.'.')) {
                return true;
            }
        }

        return false;
    }

    private function isModelAll(Expr $source): bool
    {
        foreach (NodeHelper::find($source, StaticCall::class) as $call) {
            if (NodeHelper::callName($call) === 'all' && ! LaravelCalls::isFacade($call)) {
                return true;
            }
        }

        return false;
    }
}
