<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Architecture;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Architecture\Capability;
use Heyosseus\Sloppy\Architecture\CapabilityScanner;
use Heyosseus\Sloppy\Architecture\Policy;
use Heyosseus\Sloppy\Architecture\PolicyRule;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;

/**
 * SL305 -- a class does something its role may not: queries the database
 * from a controller, reads the request in the domain, calls `env()` in a use
 * case.
 *
 * Outbound HTTP is SL208's to report, so a project that forbids `http`
 * hears about it once.
 */
final class ForbiddenCapabilityRule extends BaseRule implements PolicyRule
{
    public function id(): string
    {
        return 'SL305';
    }

    public function name(): string
    {
        return 'Forbidden Capability';
    }

    public function description(): string
    {
        return 'Flags a class that queries, dispatches, reads the request or the environment, renders or resolves '
            .'from the container where its role\'s policy forbids it.';
    }

    public function explanation(): string
    {
        return 'An architecture is mostly a statement about where side effects live. A query in a controller or '
            .'a request read in the domain still works today, but it is the one place a test cannot reach without '
            .'a database or an HTTP kernel, and the place the next person copies.';
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
        return $profile->constrainsCapabilities();
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $scanner = new CapabilityScanner($context->index);

        foreach ($context->classLikes() as $classLike) {
            $role = $context->roleOf($classLike);
            $policy = $context->architecture->profile->policyFor($role);
            $name = NodeHelper::shortName($classLike);

            if (! $policy instanceof Policy || $policy->mayNot === [] || $name === null) {
                continue;
            }

            /** @var array<string, true> $reported */
            $reported = [];

            foreach ($scanner->scan($classLike) as $use) {
                if ($use->capability === Capability::Http || ! $policy->forbids($use->capability)) {
                    continue;
                }

                $method = NodeHelper::enclosingMethod($use->node)?->name->toString() ?? 'class';
                $key = $method.'|'.$use->capability->value;

                if (isset($reported[$key])) {
                    continue;
                }

                $reported[$key] = true;

                yield $this->report(
                    context: $context,
                    at: $use->node,
                    message: sprintf(
                        '%s::%s %s (%s), which %s may not do.',
                        $name,
                        $method === 'class' ? 'class' : $method.'()',
                        $use->capability->verb(),
                        $use->evidence,
                        $policy->plural(),
                    ),
                    suggestion: trim(($policy->advice ?? '').' Move it into a class whose role allows it and '
                        .'call that from here ('.$policy->origin.').'),
                    confidence: 85,
                    fingerprint: $name.'::'.$method.'|'.$use->capability->value,
                    metrics: [
                        'role' => (string) $role,
                        'capability' => $use->capability->value,
                        'evidence' => $use->evidence,
                    ],
                );
            }
        }
    }
}
