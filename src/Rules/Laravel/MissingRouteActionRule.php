<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\SourceKind;
use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Rules\LaravelRule;

/**
 * SL211 -- a route that hands the request to a controller method nobody wrote.
 *
 * An agent adds `Route::get('/orders/export', [OrderController::class,
 * 'exportCsv'])` and moves on; the method it meant to write next never
 * arrives, or arrives as `export`. Nothing complains until somebody opens
 * the page.
 *
 * It reports only what it can prove. The class and every class above it must
 * be in the analysed paths -- or the chain must end at Laravel's own
 * `Illuminate\Routing\Controller`, whose `__call` throws -- every trait it uses
 * must be too, and nothing on the way may declare `__call`. A controller from
 * a package, or one that forwards unknown methods, is not judged.
 */
final class MissingRouteActionRule extends LaravelRule
{
    /**
     * Laravel's base controller, and the public methods it brings.
     */
    private const string FRAMEWORK_CONTROLLER = \Illuminate\Routing\Controller::class;

    private const array FRAMEWORK_METHODS = ['middleware', 'getMiddleware', 'callAction'];

    public function id(): string
    {
        return 'SL211';
    }

    public function name(): string
    {
        return 'Missing Route Action';
    }

    public function description(): string
    {
        return 'Flags a route whose action names a controller method the controller does not have.';
    }

    public function explanation(): string
    {
        return 'The route registers, `route:list` shows it, and the first request to it fails with a 500. Nothing '
            .'that reads the controller alone can see this -- the controller is not missing anything it declares -- '
            .'and a test that never visits the route will not either.';
    }

    public function category(): Category
    {
        return Category::DeadCode;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::High;
    }

    public function sourceKinds(): array
    {
        return [SourceKind::Routes];
    }

    public function analyze(AnalysisContext $context): iterable
    {
        foreach (RouteRegistration::in($context->ast()) as $route) {
            if ($route->controller === null || $route->method === null) {
                continue;
            }

            $summary = $context->index->class($route->controller);

            if (! $summary instanceof ClassSummary || $summary->kind !== 'class') {
                continue;
            }

            $methods = $this->publicMethods($summary, $context->index);

            if ($methods === null || in_array(mb_strtolower($route->method), array_map(mb_strtolower(...), $methods), true)) {
                continue;
            }

            $closest = $this->closest($route->method, $methods);
            $invokable = $route->method === '__invoke';

            yield $this->report(
                context: $context,
                at: $route->call,
                message: $invokable
                    ? sprintf('%s is routed to %s as an invokable controller, but it has no __invoke() method.', $route->label(), $summary->shortName)
                    : sprintf('%s calls %s::%s(), which %s does not have.', $route->label(), $summary->shortName, $route->method, $summary->shortName),
                suggestion: match (true) {
                    $closest !== null => sprintf('Point the route at %s::%s() if that is what it meant, or write %s().', $summary->shortName, $closest, $route->method),
                    $invokable => sprintf('Add an __invoke() method to %s, or name the method the route should call: [%s::class, \'method\'].', $summary->shortName, $summary->shortName),
                    default => sprintf('Write %s::%s(), or point the route at a method that exists.', $summary->shortName, $route->method),
                },
                confidence: $route->fromGroup ? 85 : 92,
                fingerprint: $summary->fqn.'::'.$route->method,
                metrics: [
                    'controller' => $summary->fqn,
                    'method' => $route->method,
                    'verb' => $route->verb,
                ],
            );
        }
    }

    /**
     * Public methods of a class, its traits and its parents -- or null when
     * any of them is out of sight or forwards unknown calls, so a missing
     * method cannot be proved.
     *
     * @return list<string>|null
     */
    private function publicMethods(ClassSummary $class, ProjectIndex $index): ?array
    {
        $methods = [];
        $seen = [];
        $current = $class;

        while ($current instanceof ClassSummary && ! isset($seen[$current->fqn])) {
            $seen[$current->fqn] = true;

            foreach ($current->traits as $trait) {
                if (! $index->class($trait) instanceof ClassSummary) {
                    return null;
                }
            }

            foreach ([$current, ...$index->traitsOf($current->fqn)] as $declaration) {
                foreach ($declaration->publicMethodNames as $name) {
                    $methods[] = $name;
                }
            }

            if (in_array('__call', array_map(mb_strtolower(...), $methods), true)) {
                return null;
            }

            if ($current->parent === null) {
                return $methods;
            }

            if (ltrim($current->parent, '\\') === self::FRAMEWORK_CONTROLLER) {
                return [...$methods, ...self::FRAMEWORK_METHODS];
            }

            $current = $index->class($current->parent);
        }

        return null;
    }

    /**
     * A method that differs from the one named by a typo's worth, for the
     * suggestion: `export` when the route says `exprot`, `show` for `shwo`.
     *
     * @param  list<string>  $methods
     */
    private function closest(string $wanted, array $methods): ?string
    {
        $best = null;
        $distance = 3;

        foreach ($methods as $method) {
            if (str_starts_with($method, '__') || in_array($method, self::FRAMEWORK_METHODS, true)) {
                continue;
            }

            $candidate = levenshtein(mb_strtolower($wanted), mb_strtolower($method));

            if ($candidate < $distance) {
                $best = $method;
                $distance = $candidate;
            }
        }

        return $best;
    }
}
