<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * SL206 -- controllers wired to too many collaborators.
 *
 * A controller is a thin edge. Needing many services to satisfy its actions
 * usually means the actions belong to different things, or that orchestration
 * that should live one layer down is happening here.
 */
final class ExcessiveControllerDependenciesRule extends BaseRule
{
    public function id(): string
    {
        return 'SL206';
    }

    public function name(): string
    {
        return 'Excessive Controller Dependencies';
    }

    public function description(): string
    {
        return 'Flags controllers whose constructor injects more collaborators than the configured limit.';
    }

    public function explanation(): string
    {
        return 'Every constructor dependency is resolved on every request that touches the controller, and a long '
            .'list is a reliable sign that unrelated actions have been grouped into one class. Splitting by the '
            .'dependencies each action actually needs usually produces several small, obvious controllers.';
    }

    public function category(): Category
    {
        return Category::Dependencies;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $max = max(1, $this->intOption('max_dependencies', 4));

        foreach ($context->classLikes() as $classLike) {
            if (! NodeHelper::isController($classLike)) {
                continue;
            }

            $count = NodeHelper::countDependencies($classLike);

            if ($count <= $max) {
                continue;
            }

            $name = NodeHelper::shortName($classLike);
            $constructor = NodeHelper::constructor($classLike);

            if ($name === null || ! $constructor instanceof ClassMethod) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $constructor,
                message: sprintf(
                    '%s injects %d dependencies (%s), past the limit of %d for a controller.',
                    $name,
                    $count,
                    implode(', ', array_map(
                        static fn (string $dependency): string => '$'.$dependency,
                        NodeHelper::dependencyNames($classLike),
                    )),
                    $max,
                ),
                suggestion: 'Split the controller along the lines its dependencies already suggest, or introduce a '
                    .'single action class per route so each one declares only what it uses. Injecting into the '
                    .'action method instead of the constructor also works when only one route needs a service.',
                confidence: min(92, 78 + ($count - $max) * 4),
                fingerprint: $name,
                metrics: [
                    'dependencies' => $count,
                    'max' => $max,
                ],
            );
        }
    }
}
