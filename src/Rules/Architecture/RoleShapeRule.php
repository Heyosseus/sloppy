<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Architecture;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Architecture\Policy;
use Heyosseus\Sloppy\Architecture\PolicyRule;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Modifiers;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;

/**
 * SL308 -- a class is not the shape its role says.
 *
 * Some roles are defined as much by their shape as by their place: an action
 * is one use case behind one public method, a controller in some projects is
 * a single `__invoke`. An "action" with `create()`, `update()` and `delete()`
 * is a service under another name, and the next reader trusts the name.
 *
 * Only concrete classes are judged. An abstract base class is where a role's
 * shared plumbing lives, not an instance of the role.
 */
final class RoleShapeRule extends BaseRule implements PolicyRule
{
    public function id(): string
    {
        return 'SL308';
    }

    public function name(): string
    {
        return 'Role Shape';
    }

    public function description(): string
    {
        return 'Flags a class whose public methods, or lack of final, do not fit the shape its role\'s policy declares.';
    }

    public function explanation(): string
    {
        return 'A role is a promise about what a class looks like from outside. An action that grew a second public '
            .'method is a service nobody decided to create, and a class left open in a role the project keeps final '
            .'invites the inheritance the design ruled out. The name still says one thing; the code now says another.';
    }

    public function category(): Category
    {
        return Category::Dependencies;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Low;
    }

    public function appliesTo(Profile $profile): bool
    {
        return $profile->constrainsShape();
    }

    public function analyze(AnalysisContext $context): iterable
    {
        foreach ($context->classLikes() as $classLike) {
            $role = $context->roleOf($classLike);
            $policy = $context->architecture->profile->policyFor($role);

            if (! $classLike instanceof Class_ || $classLike->isAbstract() || ! $policy instanceof Policy || ! $policy->constrainsShape()) {
                continue;
            }

            $name = $classLike->name?->toString();

            if ($name === null) {
                continue;
            }

            if ($policy->final && ! $classLike->isFinal()) {
                yield $this->report(
                    context: $context,
                    at: $classLike->name ?? $classLike,
                    message: sprintf('%s is not final, but %s must be.', $name, $policy->plural()),
                    suggestion: $this->suggestion($policy, 'Declare it final. If it really must be extended, it may not belong in this role'),
                    confidence: 90,
                    fingerprint: $name.':final',
                    metrics: ['role' => $policy->role, 'shape' => 'final'],
                );
            }

            yield from $this->publicMethods($context, $classLike, $name, $policy);
        }
    }

    /**
     * @return iterable<Finding>
     */
    private function publicMethods(AnalysisContext $context, Class_ $class, string $name, Policy $policy): iterable
    {
        foreach ($class->getMethods() as $method) {
            $methodName = $method->name->toString();

            if (! $method->isPublic() || $policy->allowsPublicMethod($methodName)) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $method->name,
                message: sprintf(
                    '%s::%s() is public, but %s may have only these public methods: %s.',
                    $name,
                    $methodName,
                    $policy->plural(),
                    $policy->publicMethodList(),
                ),
                suggestion: $this->suggestion($policy, sprintf(
                    'Make %s() private if only this class uses it; if it is a second use case, give it a class of its own',
                    $methodName,
                )),
                confidence: 85,
                fingerprint: $name.'::'.$methodName,
                metrics: ['role' => $policy->role, 'shape' => 'public_method', 'method' => $methodName],
            );
        }

        yield from $this->traitMethods($context, $class, $name, $policy);
    }

    /**
     * Public methods a class takes from the traits it uses, where the index
     * knows the trait. They are part of the class's public surface exactly
     * as if it had declared them, so a role's shape covers them too -- unless
     * the class declares the method itself or narrows it with `as private`.
     *
     * @return iterable<Finding>
     */
    private function traitMethods(AnalysisContext $context, Class_ $class, string $name, Policy $policy): iterable
    {
        $fqn = NodeHelper::className($class);

        if ($fqn === null) {
            return;
        }

        $own = [];

        foreach ($class->getMethods() as $method) {
            $own[$method->name->toLowerString()] = true;
        }

        $narrowed = [];
        $anchor = null;

        foreach ($class->getTraitUses() as $use) {
            $anchor ??= $use;

            foreach ($use->adaptations as $adaptation) {
                if ($adaptation instanceof Alias && ! $adaptation->newName instanceof Identifier && $adaptation->newModifier !== null && ($adaptation->newModifier & Modifiers::PUBLIC) === 0) {
                    $narrowed[$adaptation->method->toLowerString()] = true;
                }
            }
        }

        if (! $anchor instanceof TraitUse) {
            return;
        }

        $reported = [];

        foreach ($context->index->traitsOf($fqn) as $trait) {
            foreach ($trait->publicMethodNames as $methodName) {
                $key = mb_strtolower($methodName);

                if (isset($own[$key]) || isset($narrowed[$key]) || isset($reported[$key]) || $policy->allowsPublicMethod($methodName)) {
                    continue;
                }

                $reported[$key] = true;

                yield $this->report(
                    context: $context,
                    at: $anchor,
                    message: sprintf(
                        '%s::%s() is public through trait %s, but %s may have only these public methods: %s.',
                        $name,
                        $methodName,
                        $trait->shortName,
                        $policy->plural(),
                        $policy->publicMethodList(),
                    ),
                    suggestion: $this->suggestion($policy, sprintf(
                        'Narrow it where the trait is used -- `use %s { %s as private; }` -- or move the trait to a class whose role allows it',
                        $trait->shortName,
                        $methodName,
                    )),
                    confidence: 80,
                    fingerprint: $name.'::'.$methodName,
                    metrics: ['role' => $policy->role, 'shape' => 'public_method', 'method' => $methodName, 'trait' => $trait->fqn],
                );
            }
        }
    }

    private function suggestion(Policy $policy, string $fix): string
    {
        return trim(($policy->advice ?? '').' '.$fix.' (sloppy.architecture.policies.'.$policy->role.', '.$policy->origin.').');
    }
}
