<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Architecture;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;

/**
 * SL302 -- a class whose methods only forward to something else.
 *
 * A wrapper that adds logging, caching, retries or a narrower interface is
 * doing work. One that forwards every call unchanged adds a file, a container
 * binding and a hop, and nothing else.
 */
final class EmptyWrapperClassRule extends BaseRule
{
    public function id(): string
    {
        return 'SL302';
    }

    public function name(): string
    {
        return 'Empty Wrapper Class';
    }

    public function description(): string
    {
        return 'Flags classes whose public methods almost all forward their arguments unchanged to a single injected collaborator.';
    }

    public function explanation(): string
    {
        return 'Pure forwarding means callers pay an extra hop and an extra file to read, while the wrapper has to '
            .'be kept in step with whatever it wraps. A wrapper becomes worthwhile the moment it does something of '
            .'its own -- adapting a shape, adding caching, narrowing an interface -- and this rule stays quiet as '
            .'soon as it does.';
    }

    public function category(): Category
    {
        return Category::Architecture;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $minMethods = max(2, $this->intOption('min_methods', 2));
        $ratio = min(1.0, max(0.1, $this->floatOption('min_delegation_ratio', 0.8)));

        foreach ($context->classLikes() as $classLike) {
            if (! $classLike instanceof Class_ || $classLike->isAbstract()) {
                continue;
            }

            $name = NodeHelper::shortName($classLike);

            if ($name === null) {
                continue;
            }

            $methods = array_values(array_filter(
                NodeHelper::publicMethods($classLike),
                static fn (ClassMethod $method): bool => $method->name->toString() !== '__construct' && $method->stmts !== null,
            ));

            if (count($methods) < $minMethods) {
                continue;
            }

            $targets = $this->delegationTargets($methods);
            $delegating = array_sum($targets);

            if ($targets === [] || $delegating / count($methods) < $ratio) {
                continue;
            }

            $primary = array_key_first($targets);

            // Forwarding to several different collaborators is a facade doing
            // composition, which is a different thing.
            if ($targets[$primary] < $delegating) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $classLike,
                message: sprintf(
                    '%s forwards %d of its %d public methods straight to $this->%s without adding behaviour.',
                    $name,
                    $delegating,
                    count($methods),
                    $primary,
                ),
                suggestion: sprintf(
                    'Let callers depend on whatever $this->%s is, and delete %s. Keep it only if it is about to '
                    .'gain behaviour of its own, or if it exists to narrow a wide interface on purpose -- in which '
                    .'case that narrowing is worth a comment.',
                    $primary,
                    $name,
                ),
                confidence: $this->confidenceFrom(70, [
                    $delegating === count($methods),
                    count($methods) >= 4,
                    NodeHelper::countDependencies($classLike) === 1,
                ], 7, 88),
                fingerprint: $name,
                metrics: [
                    'delegating_methods' => $delegating,
                    'public_methods' => count($methods),
                    'target' => $primary,
                ],
            );
        }
    }

    /**
     * How many of these methods forward to each property, busiest first.
     *
     * @param  list<ClassMethod>  $methods
     * @return array<string, int>
     */
    private function delegationTargets(array $methods): array
    {
        $targets = [];

        foreach ($methods as $method) {
            $target = $this->delegationTargetOf($method);

            if ($target !== null) {
                $targets[$target] = ($targets[$target] ?? 0) + 1;
            }
        }

        arsort($targets);

        return $targets;
    }

    /**
     * The property a method forwards to, when the method does nothing else.
     *
     * The call must pass the method's own parameters through untouched: any
     * transformation, extra argument or additional statement means the wrapper
     * is contributing something.
     */
    private function delegationTargetOf(ClassMethod $method): ?string
    {
        $statements = array_values(array_filter(
            $method->stmts ?? [],
            static fn (Stmt $statement): bool => ! $statement instanceof Nop,
        ));

        if (count($statements) !== 1) {
            return null;
        }

        $statement = $statements[0];

        $expression = match (true) {
            $statement instanceof Return_ => $statement->expr,
            $statement instanceof Expression => $statement->expr,
            default => null,
        };

        if (! $expression instanceof MethodCall) {
            return null;
        }

        if (! $expression->var instanceof PropertyFetch || ! $expression->var->name instanceof Identifier) {
            return null;
        }

        if (! $expression->var->var instanceof Variable || $expression->var->var->name !== 'this') {
            return null;
        }

        if (! $this->passesParametersThrough($method, array_values($expression->getArgs()))) {
            return null;
        }

        return $expression->var->name->toString();
    }

    /**
     * @param  list<Arg>  $args
     */
    private function passesParametersThrough(ClassMethod $method, array $args): bool
    {
        $parameters = [];

        foreach ($method->params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $parameters[] = $param->var->name;
            }
        }

        if (count($args) !== count($parameters)) {
            return false;
        }

        foreach ($args as $index => $arg) {
            if (! $arg->value instanceof Variable || $arg->value->name !== ($parameters[$index] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
