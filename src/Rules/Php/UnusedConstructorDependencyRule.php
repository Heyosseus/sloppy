<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Modifiers;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;

/**
 * SL106 -- injected collaborators the class never uses.
 *
 * Left-over constructor arguments are a common by-product of iterating on a
 * class: the dependency was needed by code that has since moved out, and the
 * signature was never trimmed. Every unused argument still has to be resolved
 * from the container on every instantiation.
 */
final class UnusedConstructorDependencyRule extends BaseRule
{
    public function id(): string
    {
        return 'SL106';
    }

    public function name(): string
    {
        return 'Unused Constructor Dependency';
    }

    public function description(): string
    {
        return 'Flags constructor-injected dependencies that are never read anywhere in the class.';
    }

    public function explanation(): string
    {
        return 'An unused dependency still has to be constructed and resolved, and it misleads the next reader '
            .'about what the class actually needs. Only private properties are reported outright: a public one is '
            .'part of the class\'s API and any caller may read it, and a protected one belongs to subclasses. '
            .'Dependencies that could be consumed indirectly -- through a parent class, dynamic property access '
            .'or an attribute -- are skipped rather than guessed at.';
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
        foreach ($context->classLikes() as $classLike) {
            if (! $classLike instanceof Class_ || $classLike->isAbstract()) {
                continue;
            }

            $className = NodeHelper::shortName($classLike);
            $constructor = NodeHelper::constructor($classLike);

            if ($className === null || ! $constructor instanceof ClassMethod) {
                continue;
            }

            if (NodeHelper::hasDynamicAccess($classLike)) {
                continue;
            }

            $fqn = NodeHelper::className($classLike);
            $hasSubclasses = $fqn !== null && $context->index->implementationsOf($fqn) !== [];
            $declaredVisibility = $this->propertyVisibility($classLike);

            foreach ($constructor->params as $param) {
                $paramName = $this->reportableParameterName($param);

                if ($paramName === null) {
                    continue;
                }

                $promoted = $param->flags !== 0;
                $propertyName = $promoted
                    ? $paramName
                    : $this->assignedPropertyFor($constructor, $paramName);

                if ($propertyName === null) {
                    continue;
                }

                $visibility = $promoted
                    ? $this->promotedVisibility($param)
                    : ($declaredVisibility[$propertyName] ?? null);

                if (! $this->isReportableVisibility($visibility, $hasSubclasses)) {
                    continue;
                }

                if ($this->isUsed($classLike, $constructor, $propertyName, $paramName)) {
                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: $param,
                    message: sprintf(
                        '%s injects $%s but never uses $this->%s.',
                        $className,
                        $paramName,
                        $propertyName,
                    ),
                    suggestion: sprintf(
                        'Remove $%s from the constructor, and from any container binding or test that builds this class by hand.',
                        $paramName,
                    ),
                    confidence: 88,
                    fingerprint: $className.'::$'.$propertyName,
                    metrics: [
                        'dependency' => NodeHelper::typeToString($param->type) ?? 'mixed',
                        'promoted' => $promoted,
                    ],
                );
            }
        }
    }

    /**
     * The parameter's variable name, or null when the parameter is not the
     * kind this rule judges: a scalar, a variadic, or one carrying an
     * attribute that may inject it by other means.
     */
    private function reportableParameterName(Param $param): ?string
    {
        // The variable checks are part of the same question -- a parameter
        // this rule can name and judge -- and are folded in rather than
        // guarded separately, because a parser never produces a parameter
        // whose name is anything but a plain string.
        if (! NodeHelper::isObjectType($param) || $param->attrGroups !== []) {
            return null;
        }

        return $param->var instanceof Variable && is_string($param->var->name) ? $param->var->name : null;
    }

    /**
     * A public property is part of the class's API -- any caller may read
     * `$object->property`, and finding every one of them is not something this
     * rule can do reliably. A protected one belongs to subclasses, so it is
     * only safe to report when there are none.
     */
    private function isReportableVisibility(?string $visibility, bool $hasSubclasses): bool
    {
        return match ($visibility) {
            'private' => true,
            'protected' => ! $hasSubclasses,
            default => false,
        };
    }

    /**
     * @return array<string, string> Property name => visibility.
     */
    private function propertyVisibility(Class_ $classLike): array
    {
        $visibilities = [];

        foreach ($classLike->stmts as $statement) {
            if (! $statement instanceof Property) {
                continue;
            }

            $visibility = match (true) {
                $statement->isPrivate() => 'private',
                $statement->isProtected() => 'protected',
                default => 'public',
            };

            foreach ($statement->props as $property) {
                $visibilities[$property->name->toString()] = $visibility;
            }
        }

        return $visibilities;
    }

    private function promotedVisibility(Param $param): string
    {
        return match (true) {
            ($param->flags & Modifiers::PRIVATE) !== 0 => 'private',
            ($param->flags & Modifiers::PROTECTED) !== 0 => 'protected',
            default => 'public',
        };
    }

    /**
     * The property a plain constructor parameter is stored into, if any.
     */
    private function assignedPropertyFor(ClassMethod $constructor, string $paramName): ?string
    {
        foreach (NodeHelper::find($constructor, Assign::class) as $assign) {
            if (! $assign->var instanceof PropertyFetch || ! $assign->var->name instanceof Identifier) {
                continue;
            }

            if (! $assign->var->var instanceof Variable || $assign->var->var->name !== 'this') {
                continue;
            }

            if ($assign->expr instanceof Variable && $assign->expr->name === $paramName) {
                return $assign->var->name->toString();
            }
        }

        return null;
    }

    /**
     * Whether the dependency is read anywhere: as a property outside its own
     * assignment, or as the raw parameter inside the constructor (which covers
     * `parent::__construct($dependency)`).
     */
    private function isUsed(Class_ $classLike, ClassMethod $constructor, string $propertyName, string $paramName): bool
    {
        foreach (NodeHelper::find($classLike, PropertyFetch::class) as $fetch) {
            if (! $fetch->name instanceof Identifier || $fetch->name->toString() !== $propertyName) {
                continue;
            }

            if (! $fetch->var instanceof Variable || $fetch->var->name !== 'this') {
                continue;
            }

            $parent = $fetch->getAttribute('parent');

            // The constructor's own `$this->x = $x` is not a use of `$this->x`.
            if ($parent instanceof Assign && $parent->var === $fetch) {
                continue;
            }

            return true;
        }

        foreach (NodeHelper::find($constructor, Variable::class) as $variable) {
            if ($variable->name !== $paramName) {
                continue;
            }

            $parent = $variable->getAttribute('parent');

            if ($parent instanceof Assign && $parent->expr === $variable) {
                continue;
            }

            if ($parent instanceof Param) {
                continue;
            }

            return true;
        }

        return false;
    }
}
