<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Ast\NodeHelper;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

/**
 * One `Route::get(...)` -- or `post`, `match`, `any` -- in a routes file, and
 * what it hands the request to.
 *
 * Read off the syntax alone: `Route::middleware('auth')->get(...)`, a
 * `Route::controller(X::class)->group(...)` around a bare method name, the
 * `[X::class, 'method']` array, `'X@method'` and an invokable `X::class`. A
 * route registered any other way -- `Route::resource`, a macro, a variable
 * holding the router -- is not one of these, and no rule guesses at it.
 */
final readonly class RouteRegistration
{
    /**
     * The verbs a route can be registered with, lower-case.
     */
    private const array VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match'];

    /**
     * @param  string  $verb  Lower-case, as written: `get`, `match`, `any`...
     * @param  string|null  $uri  Null when it is not a string literal.
     * @param  string|null  $controller  Fully qualified, when the action names a controller.
     * @param  string|null  $method  The controller method, `__invoke` for an invokable one.
     */
    private function __construct(
        public StaticCall|MethodCall $call,
        public string $verb,
        public ?string $uri,
        public ?string $controller,
        public ?string $method,
        public Closure|ArrowFunction|null $closure,
        public bool $fromGroup,
    ) {}

    /**
     * Every route registration in a file, in source order.
     *
     * @param  Node|list<Node>  $subject
     * @return list<self>
     */
    public static function in(Node|array $subject): array
    {
        $routes = [];

        foreach ([...NodeHelper::find($subject, StaticCall::class), ...NodeHelper::find($subject, MethodCall::class)] as $call) {
            $route = self::from($call);

            if ($route instanceof self) {
                $routes[] = $route;
            }
        }

        usort($routes, static fn (self $a, self $b): int => $a->call->getStartFilePos() <=> $b->call->getStartFilePos());

        return $routes;
    }

    /**
     * The verb and URI as a reader would say them: `GET /orders/{order}`.
     */
    public function label(): string
    {
        return sprintf('%s %s', mb_strtoupper($this->verb), $this->uri ?? '(dynamic URI)');
    }

    private static function from(StaticCall|MethodCall $call): ?self
    {
        $verb = mb_strtolower(NodeHelper::callName($call) ?? '');

        if (! in_array($verb, self::VERBS, true) || ! self::isRouter($call)) {
            return null;
        }

        $args = NodeHelper::arguments($call);
        $offset = $verb === 'match' ? 1 : 0;
        $uri = ($args[$offset] ?? null)?->value;
        $action = ($args[$offset + 1] ?? null)?->value;

        if (! $action instanceof Expr) {
            return null;
        }

        [$controller, $method, $fromGroup] = self::target($call, $action);

        return new self(
            call: $call,
            verb: $verb,
            uri: $uri instanceof String_ ? '/'.ltrim($uri->value, '/') : null,
            controller: $controller,
            method: $method,
            closure: $action instanceof Closure || $action instanceof ArrowFunction ? $action : null,
            fromGroup: $fromGroup,
        );
    }

    /**
     * `Route::get(...)`, or a chain that starts there: `Route::middleware(...)->get(...)`.
     */
    private static function isRouter(StaticCall|MethodCall $call): bool
    {
        $root = $call instanceof StaticCall ? $call : NodeHelper::chainRoot($call);

        if (! $root instanceof StaticCall) {
            return false;
        }

        $class = NodeHelper::staticCallClass($root);

        return $class !== null && NodeHelper::baseName($class) === 'Route';
    }

    /**
     * The controller and method an action names.
     *
     * @return array{0: string|null, 1: string|null, 2: bool}
     */
    private static function target(StaticCall|MethodCall $call, Expr $action): array
    {
        if ($action instanceof Array_ && count($action->items) === 2) {
            $class = self::className($action->items[0]?->value);
            $method = $action->items[1]?->value;

            return $class !== null && $method instanceof String_ ? [$class, $method->value, false] : [null, null, false];
        }

        $class = self::className($action);

        if ($class !== null) {
            return [$class, '__invoke', false];
        }

        if (! $action instanceof String_) {
            return [null, null, false];
        }

        // `'App\Http\Controllers\OrderController@show'`. A short name has no
        // namespace to resolve it against, so it names nothing we can check.
        if (str_contains($action->value, '@')) {
            [$class, $method] = explode('@', $action->value, 2);

            return str_contains($class, '\\') && $method !== '' ? [ltrim($class, '\\'), $method, false] : [null, null, false];
        }

        $controller = self::groupController($call);

        return $controller === null ? [null, null, false] : [$controller, $action->value, true];
    }

    /**
     * The controller of the closest `Route::controller(X::class)->group(...)`
     * this route is registered inside.
     */
    private static function groupController(Node $node): ?string
    {
        foreach (NodeHelper::ancestors($node) as $ancestor) {
            if (! $ancestor instanceof Closure && ! $ancestor instanceof ArrowFunction) {
                continue;
            }

            $group = $ancestor->getAttribute('parent')?->getAttribute('parent');

            if (! $group instanceof MethodCall || ! NodeHelper::isNameOneOf(NodeHelper::callName($group), ['group'])) {
                continue;
            }

            $current = $group->var;

            while ($current instanceof MethodCall || $current instanceof StaticCall) {
                if (NodeHelper::isNameOneOf(NodeHelper::callName($current), ['controller'])) {
                    return self::className((NodeHelper::arguments($current)[0] ?? null)?->value);
                }

                $current = $current instanceof MethodCall ? $current->var : null;
            }
        }

        return null;
    }

    /**
     * `X::class`, fully qualified by the parser's name resolution.
     */
    private static function className(?Node $node): ?string
    {
        if (! $node instanceof ClassConstFetch || ! $node->class instanceof Name) {
            return null;
        }

        if (! $node->name instanceof Identifier || mb_strtolower($node->name->toString()) !== 'class') {
            return null;
        }

        return $node->class->toString();
    }
}
