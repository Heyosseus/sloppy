<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Foreach_;

/**
 * The Laravel call vocabulary the framework-aware rules share.
 *
 * Several rules need to know whether a call reads the database, writes to it,
 * sends something outward or crosses the network. Keeping those lists in one
 * place is what stops five rules from disagreeing about what counts as a query.
 */
final class LaravelCalls
{
    /**
     * Methods that end a query builder chain by going to the database.
     *
     * @var list<string>
     */
    public const array QUERY_TERMINALS = [
        'get', 'first', 'firstOrFail', 'find', 'findOrFail', 'findMany', 'sole', 'value',
        'count', 'exists', 'doesntExist', 'sum', 'avg', 'average', 'min', 'max',
        'pluck', 'paginate', 'simplePaginate', 'cursorPaginate', 'cursor', 'chunk',
        'firstWhere', 'firstOr', 'toBase',
    ];

    /**
     * Methods that start or extend a query builder chain.
     *
     * @var list<string>
     */
    public const array QUERY_BUILDERS = [
        'where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereHas', 'whereDoesntHave',
        'orWhere', 'query', 'newQuery', 'select', 'join', 'leftJoin', 'orderBy', 'groupBy',
        'having', 'limit', 'take', 'skip', 'offset', 'with', 'withCount', 'withSum', 'has',
    ];

    /**
     * Methods that write to the database.
     *
     * @var list<string>
     */
    public const array WRITES = [
        'save', 'saveOrFail', 'create', 'createMany', 'forceCreate', 'update', 'updateOrCreate',
        'firstOrCreate', 'firstOrNew', 'insert', 'insertGetId', 'insertOrIgnore', 'upsert',
        'delete', 'forceDelete', 'destroy', 'restore', 'truncate', 'increment', 'decrement',
        'attach', 'detach', 'sync', 'associate', 'push',
    ];

    /**
     * Facades and helpers that send something out of the process.
     *
     * @var list<string>
     */
    public const array OUTBOUND_FACADES = [
        'Mail', 'Notification', 'Bus', 'Queue', 'Event', 'Broadcast',
    ];

    /**
     * Methods and helpers that hand work to something else.
     *
     * @var list<string>
     */
    public const array DISPATCHERS = [
        'dispatch', 'dispatchSync', 'dispatchAfterResponse', 'dispatchNow', 'notify',
        'notifyNow', 'send', 'sendNow', 'queue', 'later', 'event', 'broadcast', 'chain',
    ];

    /**
     * Facades that are not models, so `X::all()` on them means something else.
     *
     * @var list<string>
     */
    public const array FACADES = [
        'App', 'Artisan', 'Auth', 'Blade', 'Broadcast', 'Bus', 'Cache', 'Concurrency', 'Config',
        'Context', 'Cookie', 'Crypt', 'Date', 'DB', 'Event', 'Exceptions', 'File', 'Gate', 'Hash',
        'Http', 'Lang', 'Log', 'Mail', 'Notification', 'Number', 'Password', 'Process', 'Queue',
        'RateLimiter', 'Redirect', 'Redis', 'Request', 'Response', 'Route', 'Schedule', 'Schema',
        'Session', 'Storage', 'Str', 'URL', 'Validator', 'View', 'Vite', 'Arr', 'Collection',
        'Pipeline', 'Facade',
    ];

    /**
     * Facades and functions that make an outbound HTTP request.
     *
     * @var list<string>
     */
    public const array HTTP_FUNCTIONS = [
        'curl_init', 'curl_exec', 'curl_setopt', 'curl_setopt_array',
    ];

    /**
     * Static methods a model answers that start or run a query, beyond the
     * builder, terminal and write lists. Together they are what a static call
     * on a class the index has never seen must name before it is taken for a
     * query: `Carbon::parse()` and `Uuid::uuid4()` are not.
     *
     * @var list<string>
     */
    public const array QUERY_ENTRY_POINTS = [
        'all', 'on', 'onWriteConnection', 'whereKey', 'whereKeyNot', 'whereBelongsTo', 'whereRelation', 'whereBetween',
        'whereDate', 'whereColumn', 'whereAny', 'whereAll', 'whereLike', 'whereJsonContains', 'orderByDesc', 'latest',
        'oldest', 'withTrashed', 'onlyTrashed', 'withoutTrashed', 'withoutGlobalScope', 'withoutGlobalScopes',
        'withExists', 'withAvg', 'withMin', 'withMax', 'findOr', 'findOrNew', 'firstOrNew', 'lazy', 'lazyById',
        'chunkById', 'each', 'distinct', 'lockForUpdate', 'sharedLock', 'inRandomOrder', 'whereFullText', 'scopes',
    ];

    /**
     * HTTP client classes, by namespace prefix of their fully qualified name.
     *
     * @var list<string>
     */
    private const array HTTP_CLIENT_NAMESPACES = [
        'GuzzleHttp\\', 'Psr\Http\Client\\', 'Symfony\Component\HttpClient\\', 'Http\Client\\', 'Buzz\\',
    ];

    private function __construct() {}

    /**
     * Whether a node is a call that reaches the database.
     */
    public static function isDatabaseRead(Node $node): bool
    {
        if (! $node instanceof MethodCall && ! $node instanceof StaticCall && ! $node instanceof NullsafeMethodCall) {
            return false;
        }

        $names = NodeHelper::chainMethodNames($node);

        foreach ($names as $name) {
            if (NodeHelper::isNameOneOf($name, self::QUERY_TERMINALS)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a chain looks like a query builder chain at all, terminal or not.
     */
    public static function looksLikeQuery(Node $node): bool
    {
        foreach (NodeHelper::chainMethodNames($node) as $name) {
            if (NodeHelper::isNameOneOf($name, self::QUERY_TERMINALS) || NodeHelper::isNameOneOf($name, self::QUERY_BUILDERS)) {
                return true;
            }
        }

        return false;
    }

    public static function isWrite(Node $node): bool
    {
        $name = NodeHelper::callName($node);

        return NodeHelper::isNameOneOf($name, self::WRITES);
    }

    /**
     * Whether a call writes to the database: a write method on a model, a
     * relation or a query.
     *
     * The write names are ordinary English -- `create`, `update`, `push`,
     * `delete` -- so the name alone proves nothing: `$this->service->create()`
     * and `$collection->push()` write nothing. What the call is made on has
     * to be recognisably Eloquent too: a static call on a model, a query
     * chain, a relation, or a variable that holds a model.
     */
    public static function isDatabaseWrite(Node $node, ProjectIndex $index): bool
    {
        if (! self::isWrite($node)) {
            return false;
        }

        if ($node instanceof StaticCall) {
            return self::targetsDatabase($node, $index);
        }

        return ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
            && self::isModelExpression($node->var, $index, 0);
    }

    /**
     * Whether an expression evaluates to a model, a relation or a query.
     */
    private static function isModelExpression(Expr $expr, ProjectIndex $index, int $depth): bool
    {
        if ($depth > 4) {
            return false;
        }

        $root = NodeHelper::chainRoot($expr);

        if ($root instanceof StaticCall && self::targetsDatabase($root, $index)) {
            return true;
        }

        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            // `$order->items()` -- a relation called on a model.
            return self::looksLikeQuery($expr)
                || (! $expr->isFirstClassCallable() && NodeHelper::arguments($expr) === [] && self::isModelExpression($expr->var, $index, $depth + 1));
        }

        if ($expr instanceof New_) {
            return $expr->class instanceof Name && self::isModelType($expr->class->toString(), $index);
        }

        return $expr instanceof Variable && is_string($expr->name) && self::isModelVariable($expr, $expr->name, $index, $depth);
    }

    private static function isModelVariable(Variable $variable, string $name, ProjectIndex $index, int $depth): bool
    {
        if ($name === 'this') {
            $class = NodeHelper::closestAncestor($variable, ClassLike::class);

            return $class instanceof ClassLike && NodeHelper::isEloquentModel($class, $index);
        }

        $scope = NodeHelper::closestAncestor($variable, FunctionLike::class);

        if (! $scope instanceof FunctionLike) {
            return false;
        }

        foreach ($scope->getParams() as $param) {
            if ($param->var instanceof Variable && $param->var->name === $name) {
                return self::isModelParam($param, $index);
            }
        }

        $source = self::latestSource($scope, $variable, $name);

        return $source instanceof Expr && self::isModelExpression($source, $index, $depth + 1);
    }

    /**
     * What a variable was last given before a point in a function: the value
     * of its latest assignment, or for a `foreach` value the collection being
     * walked, when that is a relation or a query.
     */
    private static function latestSource(FunctionLike $scope, Variable $at, string $name): ?Expr
    {
        $position = $at->getStartFilePos();
        $latest = null;
        $latestPosition = -1;

        foreach (NodeHelper::findOwn($scope, Assign::class) as $assign) {
            if ($assign->var instanceof Variable && $assign->var->name === $name && $assign->getStartFilePos() < $position && $assign->getStartFilePos() > $latestPosition) {
                $latest = $assign->expr;
                $latestPosition = $assign->getStartFilePos();
            }
        }

        foreach (NodeHelper::findOwn($scope, Foreach_::class) as $loop) {
            if ($loop->valueVar instanceof Variable && $loop->valueVar->name === $name && $loop->getStartFilePos() < $position && $loop->getStartFilePos() > $latestPosition) {
                // `foreach ($order->items as $item)` walks a relation's models.
                $latest = $loop->expr instanceof PropertyFetch ? $loop->expr->var : $loop->expr;
                $latestPosition = $loop->getStartFilePos();
            }
        }

        return $latest;
    }

    private static function isModelParam(Param $param, ProjectIndex $index): bool
    {
        foreach ($param->type instanceof Node ? NodeHelper::find($param->type, Name::class) : [] as $type) {
            if (self::isModelType($type->toString(), $index)) {
                return true;
            }
        }

        return false;
    }

    private static function isModelType(string $class, ProjectIndex $index): bool
    {
        return NodeHelper::extendsEloquentModel($class) || $index->isEloquentModel($class);
    }

    /**
     * Whether a static call could plausibly be reaching the database.
     *
     * Method names like `find`, `get`, `first` and `count` are not unique to
     * Eloquent -- a static helper can have all four -- so a class the project
     * index knows about and knows is not a model is ruled out, and `self::`,
     * `static::` and `parent::` are ruled out outright. A class the index has
     * never heard of is assumed to be a model from outside the analysed paths
     * -- the common case for `Order::where(...)` -- but only when the method
     * is one a model answers with a query: `Carbon::parse()` is not one.
     * Models are recognised through the project's own base classes.
     */
    public static function targetsDatabase(StaticCall $call, ProjectIndex $index): bool
    {
        $class = NodeHelper::staticCallClass($call);

        if ($class === null || NodeHelper::isSelfReference($class)) {
            return false;
        }

        if (NodeHelper::baseName($class) === 'DB') {
            return true;
        }

        if (self::isFacade($call)) {
            return false;
        }

        $summary = $index->class($class);

        if ($summary instanceof ClassSummary) {
            return $summary->isEloquentModel($index);
        }

        return self::isQueryEntryPoint(NodeHelper::callName($call));
    }

    /**
     * Whether a static method name is one a model answers by starting or
     * running a query. Dynamic wheres -- `whereEmail(...)` -- count too.
     */
    public static function isQueryEntryPoint(?string $method): bool
    {
        if ($method === null) {
            return false;
        }

        return NodeHelper::isNameOneOf($method, self::QUERY_BUILDERS)
            || NodeHelper::isNameOneOf($method, self::QUERY_TERMINALS)
            || NodeHelper::isNameOneOf($method, self::WRITES)
            || NodeHelper::isNameOneOf($method, self::QUERY_ENTRY_POINTS)
            || preg_match('/^(or)?where[A-Z]/', $method) === 1;
    }

    /**
     * Whether a static call targets a Laravel facade rather than a model.
     */
    public static function isFacade(StaticCall $call): bool
    {
        $class = NodeHelper::staticCallClass($call);

        if ($class === null) {
            return false;
        }

        if (str_contains($class, 'Illuminate\Support\Facades')) {
            return true;
        }

        return NodeHelper::isNameOneOf(NodeHelper::baseName($class), self::FACADES);
    }

    /**
     * Whether a node performs an outbound HTTP request directly.
     *
     * That is the `Http` facade, or constructing a client whose fully
     * qualified name is Guzzle's, PSR-18's, Symfony's or HTTPlug's. A class
     * merely called `Client` -- a customer model, say -- is none of those,
     * and with the index in hand a class the project declares itself is never
     * taken for one.
     */
    public static function isHttpCall(Node $node, ?ProjectIndex $index = null): bool
    {
        if ($node instanceof StaticCall) {
            $class = NodeHelper::staticCallClass($node);

            // Matched on the short name: `Http::` written without an import
            // inside a namespace still reaches the facade through its global
            // alias in a lot of real code.
            return $class !== null
                && NodeHelper::baseName($class) === 'Http'
                && ! self::isProjectClass($class, $index);
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            $class = ltrim($node->class->toString(), '\\');

            if (self::isProjectClass($class, $index)) {
                return false;
            }

            foreach (self::HTTP_CLIENT_NAMESPACES as $namespace) {
                if (str_starts_with($class, $namespace)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof Expr\FuncCall) {
            $name = NodeHelper::callName($node);

            if (NodeHelper::isNameOneOf($name, self::HTTP_FUNCTIONS)) {
                return true;
            }

            if (NodeHelper::isNameOneOf($name, ['file_get_contents', 'fopen'])) {
                $args = NodeHelper::arguments($node);

                if ($args !== [] && $args[0]->value instanceof Node\Scalar\String_) {
                    return str_starts_with(mb_strtolower($args[0]->value->value), 'http');
                }
            }
        }

        return false;
    }

    private static function isProjectClass(string $class, ?ProjectIndex $index): bool
    {
        return $index instanceof ProjectIndex && $index->class(ltrim($class, '\\')) instanceof ClassSummary;
    }

    /**
     * Whether a node hands work to a queue, a mailer, a notifier or an event.
     */
    public static function isDispatch(Node $node): bool
    {
        if ($node instanceof StaticCall) {
            $class = NodeHelper::staticCallClass($node);

            if ($class !== null && NodeHelper::isNameOneOf(NodeHelper::baseName($class), self::OUTBOUND_FACADES)) {
                return true;
            }
        }

        return NodeHelper::isNameOneOf(NodeHelper::callName($node), self::DISPATCHERS);
    }

    /**
     * Whether a static call opens a database transaction.
     */
    public static function isTransaction(Node $node): bool
    {
        if (! $node instanceof StaticCall) {
            return false;
        }

        $class = NodeHelper::staticCallClass($node);

        if ($class === null || NodeHelper::baseName($class) !== 'DB') {
            return false;
        }

        return NodeHelper::isNameOneOf(NodeHelper::callName($node), ['transaction', 'beginTransaction']);
    }
}
