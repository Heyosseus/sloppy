<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Rules\LaravelRule;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
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
    /**
     * Classes that carry a value rather than do work, recognised by name.
     *
     * @var list<string>
     */
    private const array DATA_TYPES = [
        'Carbon', 'CarbonImmutable', 'CarbonInterface',
        'DateTime', 'DateTimeImmutable', 'DateTimeInterface',
        'Collection',
    ];

    /**
     * @var list<string>
     */
    private const array SCALARS = ['int', 'float', 'string', 'bool', 'array', 'null', 'false', 'true', ''];

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
            .'changes that touch the class for unrelated reasons. Only collaborators are counted: enums, value '
            .'objects, models, dates and collections are data the class is built from, not dependencies.';
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

            $count = $this->countCollaborators($classLike, $context->index);
            $constructor = NodeHelper::constructor($classLike);

            // Collaborators are counted from constructor parameters, so a class
            // over the limit has a constructor by definition -- and a class
            // only counts as a service because of its name, so it has one of
            // those too.
            if ($count <= $max || ! $constructor instanceof ClassMethod) {
                continue;
            }

            $name = NodeHelper::shortName($classLike) ?? '';

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

    /**
     * Constructor parameters the container resolves, leaving out the data a
     * class is built from: enums, value objects, models, dates and
     * collections. A domain entity taking its fields is not over-coupled.
     */
    private function countCollaborators(ClassLike $class, ProjectIndex $index): int
    {
        $count = 0;

        foreach (NodeHelper::constructorParams($class) as $param) {
            if (NodeHelper::isObjectType($param) && ! $this->isData($param, $index)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Whether every class in the parameter's type is data rather than a
     * collaborator. Scalar and null members of a union are skipped, so
     * `?Carbon` is data and `Carbon|Mailer` is not.
     */
    private function isData(Param $param, ProjectIndex $index): bool
    {
        $printed = str_replace(['?', '&'], ['', '|'], NodeHelper::typeToString($param->type) ?? '');

        foreach (explode('|', $printed) as $part) {
            $name = trim($part);

            if (in_array(mb_strtolower($name), self::SCALARS, true)) {
                continue;
            }

            if (in_array(NodeHelper::baseName($name), self::DATA_TYPES, true)) {
                continue;
            }

            $summary = $index->class($name);

            if ($summary instanceof ClassSummary && ($summary->kind === 'enum' || $summary->isValueObject || $summary->isEloquentModel())) {
                continue;
            }

            return false;
        }

        return true;
    }
}
