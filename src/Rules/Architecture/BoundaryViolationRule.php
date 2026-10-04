<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Architecture;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Architecture\Boundaries;
use Heyosseus\Sloppy\Architecture\DependencyScanner;
use Heyosseus\Sloppy\Architecture\Module;
use Heyosseus\Sloppy\Architecture\PolicyRule;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;

/**
 * SL306 -- one module reaches into another's internals.
 *
 * In a modular monolith a module is only replaceable, or extractable, while
 * the others use it through its public surface. Every class a module names
 * from another module must be in that module's public surface or the shared
 * kernel.
 */
final class BoundaryViolationRule extends BaseRule implements PolicyRule
{
    public function id(): string
    {
        return 'SL306';
    }

    public function name(): string
    {
        return 'Boundary Violation';
    }

    public function description(): string
    {
        return 'Flags a class that uses another module\'s internal classes instead of its public surface.';
    }

    public function explanation(): string
    {
        return 'A module boundary is a promise that the inside can change without the outside noticing. Each '
            .'reference to another module\'s internals breaks that promise for both sides: the owner can no longer '
            .'refactor freely, and the caller now depends on details nobody agreed to keep.';
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
        return $profile->boundaries instanceof Boundaries;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $boundaries = $context->architecture->profile->boundaries;

        if (! $boundaries instanceof Boundaries) {
            return;
        }

        foreach ($context->classLikes() as $classLike) {
            $fqn = NodeHelper::className($classLike);
            $name = NodeHelper::shortName($classLike);

            $module = $fqn === null ? null : $boundaries->moduleOf($fqn);

            if ($fqn === null || $name === null || ! $module instanceof Module) {
                continue;
            }

            foreach (DependencyScanner::references($classLike) as $target => $node) {
                $violation = $boundaries->violation($fqn, $target);
                $targetModule = $boundaries->moduleOf($target);

                if ($violation === null || ! $targetModule instanceof Module) {
                    continue;
                }

                $surface = $boundaries->publicSurface();

                yield $this->report(
                    context: $context,
                    at: $node,
                    message: sprintf('%s uses %s, but %s.', $name, $target, $violation),
                    suggestion: $surface === ''
                        ? sprintf('Modules share nothing but the shared kernel here. Move what both need there, or declare a public surface in sloppy.architecture.boundaries (%s).', $boundaries->origin)
                        : sprintf('Go through the module\'s public surface (%s) -- add a contract or an event there if what you need is missing (%s).', $surface, $boundaries->origin),
                    confidence: 90,
                    fingerprint: $name.'->'.$target,
                    metrics: [
                        'module' => $module->name,
                        'target_module' => $targetModule->name,
                        'depends_on' => $target,
                    ],
                );
            }
        }
    }
}
