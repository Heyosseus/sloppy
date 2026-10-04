<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Architecture;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Architecture\DependencyScanner;
use Heyosseus\Sloppy\Architecture\Policy;
use Heyosseus\Sloppy\Architecture\PolicyRule;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;

/**
 * SL304 -- a class depends on something its role may not.
 *
 * The project declared which layers may see which. Every class a declaration
 * names -- extends, implements, injects, instantiates, calls statically -- is
 * checked against its role's policy, by the role the named class plays or by
 * its name.
 */
final class LayerViolationRule extends BaseRule implements PolicyRule
{
    public function id(): string
    {
        return 'SL304';
    }

    public function name(): string
    {
        return 'Layer Violation';
    }

    public function description(): string
    {
        return 'Flags a class that depends on a layer or a namespace its role\'s policy forbids.';
    }

    public function explanation(): string
    {
        return 'Layers only protect anything while the dependencies point the way the architecture says. One '
            .'controller that reaches past the service into a repository, or one domain class that imports the '
            .'framework, is how a layered codebase quietly becomes a tangled one: the next change copies it.';
    }

    public function category(): Category
    {
        return Category::Dependencies;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function appliesTo(Profile $profile): bool
    {
        return $profile->constrainsDependencies();
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $profile = $context->architecture->profile;

        foreach ($context->classLikes() as $classLike) {
            $role = $context->roleOf($classLike);
            $policy = $profile->policyFor($role);
            $name = NodeHelper::shortName($classLike);

            if (! $policy instanceof Policy || ! $policy->constrainsDependencies() || $name === null) {
                continue;
            }

            foreach (DependencyScanner::references($classLike) as $target => $node) {
                $targetRole = $context->architecture->matchClass($target, $context->index)?->name();
                $violation = $policy->dependencyViolation($target, $targetRole);

                if ($violation === null) {
                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: $node,
                    message: sprintf(
                        '%s (%s) depends on %s%s, but %s.',
                        $name,
                        $role,
                        $target,
                        $targetRole === null ? '' : ' ('.$targetRole.')',
                        $violation,
                    ),
                    suggestion: trim(($policy->advice ?? '').' Depend on a layer this role may reach, or ask '
                        .'the architecture to allow it in sloppy.architecture.policies.'.$role.' ('.$policy->origin.').'),
                    confidence: 90,
                    fingerprint: $name.'->'.$target,
                    metrics: [
                        'role' => (string) $role,
                        'depends_on' => $target,
                        'depends_on_role' => $targetRole ?? '',
                    ],
                );
            }
        }
    }
}
