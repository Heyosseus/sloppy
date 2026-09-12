<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\LaravelRule;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * SL207 -- services wired to too many collaborators.
 *
 * Scored more leniently than SL206: a service is where coordination is
 * supposed to happen, so a few collaborators are expected. The threshold is
 * higher and confidence climbs more slowly.
 */
final class ExcessiveServiceDependenciesRule extends LaravelRule
{
    public function id(): string
    {
        return 'SL207';
    }

    public function name(): string
    {
        return 'Excessive Service Dependencies';
    }

    public function description(): string
    {
        return 'Flags service, action and manager classes whose constructor injects more collaborators than the configured limit.';
    }

    public function explanation(): string
    {
        return 'Coordination is a service\'s job, so several dependencies are normal. Past a point the class is '
            .'coordinating more than one workflow, which shows up as tests that need half the container mocked and '
            .'changes that touch the class for unrelated reasons.';
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
        $max = max(1, $this->intOption('max_dependencies', 7));

        foreach ($context->classLikes() as $classLike) {
            // Controllers have their own, stricter rule.
            if (NodeHelper::isController($classLike) || ! NodeHelper::isServiceClass($classLike)) {
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
                    '%s injects %d dependencies, past the limit of %d for a service class.',
                    $name,
                    $count,
                    $max,
                ),
                suggestion: 'Look for a subset of the dependencies that only some methods use -- that subset is '
                    .'usually a class of its own. Where several dependencies are always used together, one '
                    .'collaborator wrapping them often replaces the group.',
                confidence: min(88, 68 + ($count - $max) * 5),
                fingerprint: $name,
                metrics: [
                    'dependencies' => $count,
                    'max' => $max,
                ],
            );
        }
    }
}
