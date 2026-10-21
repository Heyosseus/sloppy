<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\SourceKind;
use Heyosseus\Sloppy\Rules\LaravelRule;

/**
 * SL213 -- a GET route that changes something.
 *
 * `Route::get('/orders/{order}/delete', ...)` is the quickest way to make a
 * link that deletes, and agents take it. GET requests skip CSRF protection,
 * and browsers, link previews and crawlers fetch them without asking: the
 * order goes the first time somebody pastes the URL into a chat.
 *
 * Two signals, read off the route alone. The action is one of the resource
 * methods that change state -- `store`, `update`, `destroy` -- or a URI
 * segment is a destructive verb: `/delete`, `/remove`. A segment that only
 * starts with one (`/delete-account`) is often the page that asks for
 * confirmation, so it is reported with less confidence.
 */
final class DestructiveGetRouteRule extends LaravelRule
{
    /**
     * @var list<string>
     */
    private const array METHODS = ['store', 'update', 'destroy', 'delete', 'remove'];

    /**
     * @var list<string>
     */
    private const array VERBS = ['delete', 'destroy', 'remove', 'purge', 'erase'];

    public function id(): string
    {
        return 'SL213';
    }

    public function name(): string
    {
        return 'Destructive GET Route';
    }

    public function description(): string
    {
        return 'Flags a GET route that points at store, update or destroy, or whose URI says it deletes or removes something.';
    }

    public function explanation(): string
    {
        return 'Laravel does not check a CSRF token on GET, and browsers, link previews and crawlers follow GET '
            .'links on their own. A route that changes data on GET can be triggered by an image tag on another '
            .'site, or by a chat app unfurling the link.';
    }

    public function category(): Category
    {
        return Category::Laravel;
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
            if ($route->verb !== 'get') {
                continue;
            }

            $method = $route->method !== null && in_array(mb_strtolower($route->method), self::METHODS, true) ? $route->method : null;
            [$segment, $exact] = $this->destructiveSegment($route->uri ?? '');

            if ($method === null && $segment === null) {
                continue;
            }

            $verb = $this->verbFor($method ?? $segment ?? '');

            yield $this->report(
                context: $context,
                at: $route->call,
                message: $method !== null
                    ? sprintf('%s is routed to %s(), an action that changes data, over GET.', $route->label(), $method)
                    : sprintf('%s says "%s" in its URI, and GET requests are not meant to change anything.', $route->label(), $segment),
                suggestion: sprintf(
                    'Register it with Route::%s() and send it from a form with @csrf%s. Keep a GET route only for the page that asks for confirmation.',
                    $verb,
                    $verb === 'post' ? '' : sprintf(' and @method(\'%s\')', mb_strtoupper($verb)),
                ),
                confidence: match (true) {
                    $method !== null => 90,
                    $exact => 85,
                    default => 65,
                },
                fingerprint: $route->label(),
                metrics: [
                    'uri' => $route->uri ?? '',
                    'method' => $route->method ?? '',
                ],
                severity: $method === null && ! $exact ? Severity::Medium : null,
            );
        }
    }

    /**
     * The URI segment naming a destructive verb, and whether it is the whole
     * segment rather than the start of one.
     *
     * @return array{0: string|null, 1: bool}
     */
    private function destructiveSegment(string $uri): array
    {
        $prefix = null;

        foreach (explode('/', mb_strtolower($uri)) as $segment) {
            if ($segment === '' || str_starts_with($segment, '{')) {
                continue;
            }

            if (in_array($segment, self::VERBS, true)) {
                return [$segment, true];
            }

            foreach (self::VERBS as $verb) {
                if ($prefix === null && (str_starts_with($segment, $verb.'-') || str_starts_with($segment, $verb.'_'))) {
                    $prefix = $segment;
                }
            }
        }

        return [$prefix, false];
    }

    private function verbFor(string $action): string
    {
        $action = mb_strtolower($action);

        return match (true) {
            str_starts_with($action, 'store') => 'post',
            str_starts_with($action, 'update') => 'put',
            default => 'delete',
        };
    }
}
