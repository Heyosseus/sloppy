<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\SourceKind;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\LaravelRule;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;

/**
 * SL212 -- a route closure doing a controller's job.
 *
 * `Route::get('/', fn () => view('welcome'))` is what closures in a routes
 * file are for. One that queries, writes or validates is a controller action
 * written where nobody looks for one: it cannot be injected, reused or found
 * by a reader browsing app/Http, and the next agent adds its own beside it.
 */
final class LogicInRouteClosureRule extends LaravelRule
{
    public function id(): string
    {
        return 'SL212';
    }

    public function name(): string
    {
        return 'Logic In Route Closure';
    }

    public function description(): string
    {
        return 'Flags a closure in a routes file that queries or writes the database, validates input, or runs longer than a few statements.';
    }

    public function explanation(): string
    {
        return 'A routes file is the map of an application. A closure there that does the work is a controller '
            .'action without a name, a constructor or a home: nothing else can call it, and a reader looking '
            .'for the behaviour in app/Http will not find it. A closure that returns a view or a redirect is fine.';
    }

    public function category(): Category
    {
        return Category::Laravel;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function sourceKinds(): array
    {
        return [SourceKind::Routes];
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $maxStatements = max(1, $this->intOption('max_statements', 10));

        foreach (RouteRegistration::in($context->ast()) as $route) {
            if ($route->closure === null) {
                continue;
            }

            $statements = NodeHelper::countStatements($route->closure);
            $concerns = array_keys(array_filter([
                'queries the database' => $this->any($route->closure, static fn (Node $node): bool => $node instanceof StaticCall
                    && ! LaravelCalls::isWrite($node) && LaravelCalls::targetsDatabase($node, $context->index)),
                'writes to the database' => $this->any($route->closure, fn (Node $node): bool => LaravelCalls::isDatabaseWrite($node, $context->index)),
                'validates input' => $this->any($route->closure, static fn (Node $node): bool => ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
                    && NodeHelper::isNameOneOf(NodeHelper::callName($node), ['validate', 'validateWithBag'])),
                sprintf('runs %d statements', $statements) => $statements > $maxStatements,
            ]));

            if ($concerns === []) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $route->closure,
                message: sprintf('The closure for %s %s.', $route->label(), $this->sentence($concerns)),
                suggestion: 'Move it into a controller action and point the route at it: [SomeController::class, \'method\']. '
                    .'The route stays one line, and the action can take its dependencies through the constructor.',
                confidence: $this->confidenceFrom(70, array_fill(0, count($concerns) - 1, true), perSignal: 8, ceiling: 90),
                fingerprint: $route->label(),
                metrics: [
                    'statements' => $statements,
                    'concerns' => count($concerns),
                ],
                severity: count($concerns) === 1 ? Severity::Low : null,
            );
        }
    }

    /**
     * @param  callable(Node): bool  $test
     */
    private function any(Node $closure, callable $test): bool
    {
        foreach (NodeHelper::find($closure, Node::class) as $node) {
            if ($test($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  non-empty-list<string>  $parts
     */
    private function sentence(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? $last : implode(', ', $parts).' and '.$last;
    }
}
