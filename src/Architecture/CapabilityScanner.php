<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Rules\Laravel\LaravelCalls;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;

/**
 * Finds what a class does that an architecture policy can forbid.
 *
 * Tuned for precision over recall, because a policy finding says "your
 * architecture forbids this" and must be right when it says it. A capability
 * counts only where the code says so unambiguously: a static call on a class
 * the index knows is an Eloquent model, the `DB`, `Http`, `View` or `App`
 * facades, helpers such as `env()` and `request()`, and the framework types a
 * class injects. `$order->save()` on a variable is not counted -- nothing
 * here can tell it from any other object's `save()`.
 */
final readonly class CapabilityScanner
{
    /**
     * Injected types that hand a class a capability without a single call
     * site naming it.
     *
     * @var array<string, list<Capability>>
     */
    private const array INJECTED = [
        \Illuminate\Http\Client\Factory::class => [Capability::Http],
        \GuzzleHttp\Client::class => [Capability::Http],
        \GuzzleHttp\ClientInterface::class => [Capability::Http],
        \Psr\Http\Client\ClientInterface::class => [Capability::Http],
        \Illuminate\Database\DatabaseManager::class => [Capability::DatabaseRead, Capability::DatabaseWrite],
        \Illuminate\Database\ConnectionInterface::class => [Capability::DatabaseRead, Capability::DatabaseWrite],
        \Illuminate\Database\Connection::class => [Capability::DatabaseRead, Capability::DatabaseWrite],
        \Illuminate\Contracts\Bus\Dispatcher::class => [Capability::Dispatch],
        \Illuminate\Contracts\Events\Dispatcher::class => [Capability::Dispatch],
        \Illuminate\Contracts\Mail\Mailer::class => [Capability::Dispatch],
        \Illuminate\Contracts\Notifications\Dispatcher::class => [Capability::Dispatch],
        \Illuminate\Contracts\Queue\Queue::class => [Capability::Dispatch],
        \Illuminate\Contracts\View\Factory::class => [Capability::View],
        \Illuminate\View\Factory::class => [Capability::View],
        \Illuminate\Contracts\Container\Container::class => [Capability::Container],
        \Illuminate\Contracts\Foundation\Application::class => [Capability::Container],
        \Illuminate\Foundation\Application::class => [Capability::Container],
        \Illuminate\Container\Container::class => [Capability::Container],
    ];

    private const array DB_WRITES = [
        'insert', 'insertGetId', 'insertOrIgnore', 'upsert', 'update', 'delete', 'truncate',
        'statement', 'unprepared', 'affectingStatement', 'transaction', 'beginTransaction', 'commit',
    ];

    private const array QUERY_STARTS = ['all', 'query', 'newQuery', 'table', 'raw', 'select', 'selectOne', 'cursor'];

    private const array DISPATCH_FACADES = ['Mail', 'Notification', 'Bus', 'Queue', 'Event', 'Broadcast'];

    private const array STATIC_DISPATCH = ['dispatch', 'dispatchSync', 'dispatchIf', 'dispatchUnless', 'dispatchAfterResponse'];

    private const array FORM_REQUESTS = [\Illuminate\Foundation\Http\FormRequest::class];

    public function __construct(private ProjectIndex $index) {}

    /**
     * Every capability use in a class, in source order.
     *
     * @return list<CapabilityUse>
     */
    public function scan(ClassLike $class): array
    {
        $uses = [
            ...$this->injected($class),
            ...$this->requestParameters($class),
        ];

        foreach (NodeHelper::find($class, StaticCall::class) as $call) {
            $uses = [...$uses, ...$this->staticCall($call)];
        }

        foreach (NodeHelper::find($class, FuncCall::class) as $call) {
            $uses = [...$uses, ...$this->functionCall($call)];
        }

        foreach (NodeHelper::find($class, New_::class) as $new) {
            if (LaravelCalls::isHttpCall($new) && $new->class instanceof Name) {
                $uses[] = new CapabilityUse(Capability::Http, $new, 'new '.NodeHelper::baseName($new->class->toString()));
            }
        }

        foreach ([...NodeHelper::find($class, MethodCall::class), ...NodeHelper::find($class, NullsafeMethodCall::class)] as $call) {
            if (NodeHelper::isNameOneOf(NodeHelper::callName($call), ['notify', 'notifyNow'])) {
                $uses[] = new CapabilityUse(Capability::Dispatch, $call, '->'.NodeHelper::callName($call).'()');
            }
        }

        usort($uses, static fn (CapabilityUse $a, CapabilityUse $b): int => [$a->node->getStartLine(), $a->node->getStartFilePos()] <=> [$b->node->getStartLine(), $b->node->getStartFilePos()]);

        return $uses;
    }

    /**
     * @return list<CapabilityUse>
     */
    private function injected(ClassLike $class): array
    {
        $uses = [];

        foreach (NodeHelper::constructorParams($class) as $param) {
            foreach ($this->typeNames($param) as $type) {
                foreach (self::INJECTED[$type] ?? [] as $capability) {
                    $uses[] = new CapabilityUse($capability, $param, 'injects '.$type);
                }
            }
        }

        return $uses;
    }

    /**
     * A method that takes the request -- `Request $request` or a form request
     * -- reads it, wherever the method is.
     *
     * @return list<CapabilityUse>
     */
    private function requestParameters(ClassLike $class): array
    {
        $uses = [];

        foreach (NodeHelper::methods($class) as $method) {
            foreach ($method->params as $param) {
                foreach ($this->typeNames($param) as $type) {
                    if ($type === \Illuminate\Http\Request::class || $this->index->extendsAny($this->index->class($type)?->parent, self::FORM_REQUESTS) || in_array($type, self::FORM_REQUESTS, true)) {
                        $uses[] = new CapabilityUse(Capability::Request, $param, 'takes '.NodeHelper::baseName($type));
                    }
                }
            }
        }

        return $uses;
    }

    /**
     * @return list<CapabilityUse>
     */
    private function staticCall(StaticCall $call): array
    {
        $class = NodeHelper::staticCallClass($call);
        $method = NodeHelper::callName($call);

        if ($class === null || $method === null || NodeHelper::isSelfReference($class)) {
            return [];
        }

        $base = NodeHelper::baseName($class);
        $evidence = sprintf('%s::%s()', $base, $method);
        $facade = $class === $base || str_starts_with($class, 'Illuminate\Support\Facades\\');

        $capability = match (true) {
            $facade && $base === 'DB' => $this->databaseCapability($call, self::DB_WRITES, true),
            $this->isModel($class) => $this->databaseCapability($call, LaravelCalls::WRITES, false),
            LaravelCalls::isHttpCall($call) => Capability::Http,
            $facade && in_array($base, self::DISPATCH_FACADES, true) => Capability::Dispatch,
            NodeHelper::isNameOneOf($method, self::STATIC_DISPATCH) => Capability::Dispatch,
            $facade && $base === 'Request' => Capability::Request,
            ($facade && $base === 'View') || $base === 'Inertia' => Capability::View,
            ($facade && $base === 'App') || ($class === \Illuminate\Container\Container::class && $method === 'getInstance') => Capability::Container,
            default => null,
        };

        return $capability instanceof Capability ? [new CapabilityUse($capability, $call, $evidence)] : [];
    }

    /**
     * Read or write, judged by the whole chain: `Order::where(...)->update()`
     * writes, `Order::where(...)->get()` reads. A model static that is neither
     * -- `Order::statusLabels()` -- is not a query at all.
     *
     * @param  list<string>  $writes
     */
    private function databaseCapability(StaticCall $call, array $writes, bool $anyMethodReads): ?Capability
    {
        $names = NodeHelper::chainMethodNames(NodeHelper::outermostChain($call));

        foreach ($names as $name) {
            if (NodeHelper::isNameOneOf($name, $writes)) {
                return Capability::DatabaseWrite;
            }
        }

        if ($anyMethodReads) {
            return Capability::DatabaseRead;
        }

        foreach ($names as $name) {
            if (NodeHelper::isNameOneOf($name, [...LaravelCalls::QUERY_TERMINALS, ...LaravelCalls::QUERY_BUILDERS, ...self::QUERY_STARTS])) {
                return Capability::DatabaseRead;
            }
        }

        return null;
    }

    /**
     * @return list<CapabilityUse>
     */
    private function functionCall(FuncCall $call): array
    {
        $name = NodeHelper::callName($call);

        $capability = match (true) {
            NodeHelper::isNameOneOf($name, ['env']) => Capability::Env,
            NodeHelper::isNameOneOf($name, ['request']) => Capability::Request,
            NodeHelper::isNameOneOf($name, ['view']) => Capability::View,
            NodeHelper::isNameOneOf($name, ['app', 'resolve']) => Capability::Container,
            NodeHelper::isNameOneOf($name, ['dispatch', 'dispatch_sync', 'event', 'broadcast']) => Capability::Dispatch,
            LaravelCalls::isHttpCall($call) => Capability::Http,
            default => null,
        };

        return $capability instanceof Capability ? [new CapabilityUse($capability, $call, ltrim((string) $name, '\\').'()')] : [];
    }

    /**
     * Whether the index knows the class and it is an Eloquent model, however
     * many of the project's own base classes stand in between.
     */
    private function isModel(string $class): bool
    {
        $seen = [];
        $summary = $this->index->class($class);

        while ($summary instanceof \Heyosseus\Sloppy\Ast\ClassSummary && ! isset($seen[$summary->fqn])) {
            if (NodeHelper::extendsEloquentModel($summary->parent)) {
                return $summary->kind === 'class';
            }

            $seen[$summary->fqn] = true;
            $summary = $summary->parent === null ? null : $this->index->class($summary->parent);
        }

        return false;
    }

    /**
     * Class names in a parameter's type, through nullable, union and
     * intersection types.
     *
     * @return list<string>
     */
    private function typeNames(Param $param): array
    {
        return $param->type instanceof Node
            ? array_map(static fn (Name $name): string => $name->toString(), NodeHelper::find($param->type, Name::class))
            : [];
    }
}
