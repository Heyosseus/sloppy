<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Architecture\Capability;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\LaravelRule;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassLike;

/**
 * SL208 -- outbound HTTP requests made from a layer that should not know about
 * the network.
 *
 * The point is not that HTTP calls need a repository. It is that a controller,
 * a model or a middleware talking to a third party directly cannot be tested
 * without the network, and the details of that integration leak into code that
 * has nothing to do with it. Classes that exist to be the boundary -- clients,
 * gateways, connectors, services -- are not flagged.
 */
final class DirectExternalApiRule extends LaravelRule
{
    /**
     * Name fragments that mark a class as an intentional integration boundary.
     *
     * @var list<string>
     */
    private const array BOUNDARY_NAMES = [
        'Client', 'Gateway', 'Connector', 'Integration', 'Api', 'Adapter', 'Driver', 'Transport', 'Webhook',
    ];

    public function id(): string
    {
        return 'SL208';
    }

    public function name(): string
    {
        return 'Direct External API Call';
    }

    public function description(): string
    {
        return 'Flags outbound HTTP requests made directly from controllers, models, form requests or middleware.';
    }

    public function explanation(): string
    {
        return 'An HTTP call in these layers cannot be exercised without either the network or framework-wide '
            .'faking, and the endpoint, headers and payload shape end up spread across code that should not know '
            .'them. Behind one named client, the integration can be swapped, retried, logged and faked in a single '
            .'place.';
    }

    public function category(): Category
    {
        return Category::Laravel;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        foreach ($context->classLikes() as $classLike) {
            // An anonymous class is code its host runs, so it is judged as
            // part of the host -- walked below with it -- rather than a
            // second time on its own.
            if (NodeHelper::className($classLike) === null) {
                continue;
            }

            $layer = $this->layerOf($context, $classLike);

            if ($layer === null || $this->isBoundary($classLike)) {
                continue;
            }

            $className = NodeHelper::shortName($classLike) ?? 'class';

            /** @var array<string, true> $reported */
            $reported = [];

            foreach ([StaticCall::class, New_::class, FuncCall::class] as $type) {
                foreach (NodeHelper::find($classLike, $type) as $node) {
                    if (! LaravelCalls::isHttpCall($node, $context->index)) {
                        continue;
                    }

                    $method = NodeHelper::enclosingMethod($node);
                    $methodName = $method?->name->toString() ?? 'closure';

                    if (isset($reported[$methodName])) {
                        continue;
                    }

                    $reported[$methodName] = true;

                    yield $this->report(
                        context: $context,
                        at: $node,
                        message: sprintf(
                            '%s::%s() makes an outbound HTTP request directly from %s.',
                            $className,
                            $methodName,
                            $layer,
                        ),
                        suggestion: sprintf(
                            'Extract the integration behind a dedicated client -- for example %sClient -- and inject '
                            .'it here. The %s then depends on an interface it can fake, and retries, timeouts and '
                            .'logging live in one place.',
                            str_replace(['Controller', 'Middleware', 'Request'], '', $className),
                            $layer,
                        ),
                        confidence: 85,
                        fingerprint: $className.'::'.$methodName,
                        metrics: [
                            'layer' => $layer,
                            'call' => NodeHelper::printAny($node),
                        ],
                    );
                }
            }
        }
    }

    /**
     * The layer this class belongs to, or null when the rule has no opinion
     * about it. Beyond the four Laravel layers, any role whose policy forbids
     * `http` is one: this rule reports outbound HTTP for the architecture
     * rules, so a forbidden request is reported once.
     */
    private function layerOf(AnalysisContext $context, ClassLike $classLike): ?string
    {
        $role = $context->roleOf($classLike);

        return match ($role) {
            'controller' => 'a controller',
            'model' => 'an Eloquent model',
            'form-request' => 'a form request',
            'middleware' => 'middleware',
            default => $context->architecture->profile->policyFor($role)?->forbids(Capability::Http) === true
                ? sprintf('a class in the %s role', $role)
                : null,
        };
    }

    private function isBoundary(ClassLike $classLike): bool
    {
        $name = NodeHelper::className($classLike) ?? '';

        foreach (self::BOUNDARY_NAMES as $fragment) {
            if (str_ends_with($name, $fragment) || str_contains($name, $fragment.'\\')) {
                return true;
            }
        }

        return false;
    }
}
