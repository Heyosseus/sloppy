<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;

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
     * Whether a static call could plausibly be reaching the database.
     *
     * Method names like `find`, `get`, `first` and `count` are not unique to
     * Eloquent -- a static helper can have all four -- so a class the project
     * index knows about and knows is not a model is ruled out, and `self::`,
     * `static::` and `parent::` are ruled out outright. A class the index has
     * never heard of is assumed to be a model from outside the analysed paths,
     * which is the common case for `Order::where(...)`.
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

        return ! $summary instanceof ClassSummary || $summary->isEloquentModel();
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
     */
    public static function isHttpCall(Node $node): bool
    {
        if ($node instanceof StaticCall) {
            $class = NodeHelper::staticCallClass($node);

            return $class !== null && NodeHelper::baseName($class) === 'Http';
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            $class = $node->class->toString();

            return str_contains($class, 'GuzzleHttp') || NodeHelper::baseName($class) === 'Client';
        }

        if ($node instanceof Node\Expr\FuncCall) {
            $name = NodeHelper::callName($node);

            if (NodeHelper::isNameOneOf($name, self::HTTP_FUNCTIONS)) {
                return true;
            }

            if (NodeHelper::isNameOneOf($name, ['file_get_contents', 'fopen'])) {
                $args = $node->getArgs();

                if ($args !== [] && $args[0]->value instanceof Node\Scalar\String_) {
                    return str_starts_with(mb_strtolower($args[0]->value->value), 'http');
                }
            }
        }

        return false;
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
