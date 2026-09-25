<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Trait_;

/**
 * SL102 -- classes that have accumulated more than one reason to change.
 */
final class GodClassRule extends BaseRule
{
    /**
     * Framework base classes whose subclasses have a public surface the
     * framework dictates: hooks it calls, fluent configuration it expects.
     * A class extending one, directly or through a project base class, gets
     * `framework_leniency` times the size limits.
     *
     * @var list<string>
     */
    public const array FRAMEWORK_BASES = [
        'Filament\Actions\Action',
        'Filament\Forms\Components\Component',
        'Filament\Forms\Components\Field',
        'Filament\Infolists\Components\Entry',
        'Filament\Pages\Page',
        'Filament\Resources\Pages\Page',
        'Filament\Resources\Resource',
        'Filament\Schemas\Components\Component',
        'Filament\Tables\Columns\Column',
        'Filament\Tables\Filters\BaseFilter',
        \Filament\Widgets\Widget::class,
        'Livewire\Component',
    ];

    public function id(): string
    {
        return 'SL102';
    }

    public function name(): string
    {
        return 'God Class';
    }

    public function description(): string
    {
        return 'Flags classes that are large across several dimensions at once: size, method count, injected dependencies and the number of collaborators they talk to.';
    }

    public function explanation(): string
    {
        return 'Method count on its own says very little -- an Eloquent model with twenty accessors is fine. '
            .'This fires when size, dependency count and the breadth of collaborators rise together, which is the '
            .'shape of a class that has absorbed several responsibilities and now has several reasons to change. '
            .'Getters and fluent setters do not count as methods, classes built on a framework base get wider '
            .'limits, and a class that talks to few collaborators needs one more signal than usual.';
    }

    public function category(): Category
    {
        return Category::Complexity;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::High;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $minSignals = max(1, $this->intOption('min_signals', 2));
        $maxDependencies = $this->intOption('max_dependencies', 8);
        $maxCollaborators = $this->intOption('max_collaborators', 15);

        foreach ($context->classLikes() as $classLike) {
            $name = NodeHelper::shortName($classLike);

            if ((! $classLike instanceof Class_ && ! $classLike instanceof Trait_) || $name === null) {
                continue;
            }

            $measured = $this->measure($classLike);
            $leniency = $this->leniency($context, $classLike);

            $signals = [
                $measured['lines'] > (int) ($this->intOption('max_lines', 300) * $leniency),
                $measured['methods'] > (int) ($this->intOption('max_methods', 20) * $leniency),
                $measured['public_methods'] > (int) ($this->intOption('max_public_methods', 15) * $leniency),
                $measured['statements'] > (int) ($this->intOption('max_statements', 200) * $leniency),
                $measured['dependencies'] > $maxDependencies,
                $measured['collaborators'] > $maxCollaborators,
            ];

            $triggered = count(array_filter($signals));

            // A class that reaches out to little is hard to call a god class
            // on size alone: its methods are hooks and helpers around one
            // concern, so it needs one more signal than usual.
            $isContained = $measured['collaborators'] <= $maxCollaborators && $measured['dependencies'] <= $maxDependencies;

            if ($triggered < ($isContained ? $minSignals + 1 : $minSignals)) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $classLike,
                message: $this->describe($name, $measured),
                suggestion: 'Group the members that change together and move each group into its own class. '
                    .'Clusters of methods that share the same subset of properties are usually a class trying to '
                    .'get out.',
                confidence: $this->confidenceFrom(55, $signals, 9, 94),
                fingerprint: $name,
                metrics: [...$measured, 'signals' => $triggered],
            );
        }
    }

    /**
     * @return array{lines: int, methods: int, public_methods: int, accessors: int, statements: int, dependencies: int, collaborators: int}
     */
    private function measure(ClassLike $classLike): array
    {
        $methods = NodeHelper::methods($classLike);
        $public = NodeHelper::publicMethods($classLike);
        // Getters and fluent setters are the surface a framework asks for,
        // not responsibilities, so they are counted apart.
        $accessors = count(array_filter($public, $this->isAccessor(...)));

        return [
            'lines' => NodeHelper::lineSpan($classLike),
            'methods' => count($methods) - $accessors,
            'public_methods' => count($public) - $accessors,
            'accessors' => $accessors,
            'statements' => NodeHelper::countStatements($classLike),
            'dependencies' => NodeHelper::countDependencies($classLike),
            'collaborators' => NodeHelper::countDistinctCallTargets($classLike),
        ];
    }

    /**
     * How much wider than usual the size limits are for this class.
     *
     * Models carry many small members by design: relations, casts, scopes and
     * accessors. Classes built on a framework base -- a Filament resource, a
     * Livewire component -- have a public surface the framework dictates.
     * Judging either by the method count of a service would flag ordinary
     * code.
     */
    private function leniency(AnalysisContext $context, ClassLike $classLike): float
    {
        if (NodeHelper::isEloquentModel($classLike)) {
            return $this->floatOption('model_leniency', 1.5);
        }

        $bases = $this->listOption('framework_bases', self::FRAMEWORK_BASES);

        return $context->index->extendsAny(NodeHelper::parentName($classLike), $bases)
            ? $this->floatOption('framework_leniency', 1.5)
            : 1.0;
    }

    /**
     * @param  array{lines: int, methods: int, public_methods: int, accessors: int, statements: int, dependencies: int, collaborators: int}  $measured
     */
    private function describe(string $name, array $measured): string
    {
        return sprintf(
            '%s spans %d lines with %d methods (%d public%s), %d statements%s and talks to %d distinct collaborators.',
            $name,
            $measured['lines'],
            $measured['methods'],
            $measured['public_methods'],
            $measured['accessors'] > 0 ? sprintf(', not counting %d accessors', $measured['accessors']) : '',
            $measured['statements'],
            // Framework-resolved classes rarely take anything through the
            // constructor, so a zero says nothing about the class.
            $measured['dependencies'] > 0 ? sprintf(', %d injected dependencies', $measured['dependencies']) : '',
            $measured['collaborators'],
        );
    }

    /**
     * Whether a method only reads or writes the object's own state: a getter
     * (`return $this->label;`, or Filament's `return $this->evaluate($this->label);`)
     * or a fluent setter (`$this->label = $label; return $this;`).
     */
    private function isAccessor(ClassMethod $method): bool
    {
        $stmts = $method->stmts ?? [];
        $last = end($stmts);

        if ($method->isStatic() || ! $last instanceof Return_ || ! $last->expr instanceof Expr || count($stmts) > 2) {
            return false;
        }

        if (count($stmts) === 2) {
            return $this->isThis($last->expr)
                && $stmts[0] instanceof Expression
                && $stmts[0]->expr instanceof Assign
                && $this->isOwnProperty($stmts[0]->expr->var);
        }

        return $this->isOwnProperty($last->expr) || $this->isOwnPropertyEvaluated($last->expr);
    }

    private function isOwnPropertyEvaluated(Expr $expr): bool
    {
        if (! $expr instanceof MethodCall || ! $this->isThis($expr->var) || $expr->args === []) {
            return false;
        }

        foreach ($expr->args as $arg) {
            if (! $arg instanceof Arg || ! $this->isOwnProperty($arg->value)) {
                return false;
            }
        }

        return true;
    }

    private function isOwnProperty(Expr $expr): bool
    {
        return $expr instanceof PropertyFetch && $this->isThis($expr->var) && $expr->name instanceof Identifier;
    }

    private function isThis(Expr $expr): bool
    {
        return $expr instanceof Variable && $expr->name === 'this';
    }
}
