<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Trait_;

/**
 * SL102 -- classes that have accumulated more than one reason to change.
 */
final class GodClassRule extends BaseRule
{
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
            .'shape of a class that has absorbed several responsibilities and now has several reasons to change.';
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
        $maxLines = $this->intOption('max_lines', 300);
        $maxMethods = $this->intOption('max_methods', 20);
        $maxPublicMethods = $this->intOption('max_public_methods', 15);
        $maxStatements = $this->intOption('max_statements', 200);
        $maxDependencies = $this->intOption('max_dependencies', 8);
        $maxCollaborators = $this->intOption('max_collaborators', 15);
        $minSignals = max(1, $this->intOption('min_signals', 2));
        $modelLeniency = $this->floatOption('model_leniency', 1.5);

        foreach ($context->classLikes() as $classLike) {
            if (! $classLike instanceof Class_ && ! $classLike instanceof Trait_) {
                continue;
            }

            $name = NodeHelper::shortName($classLike);

            if ($name === null) {
                continue;
            }

            // Models carry many small members by design: relations, casts,
            // scopes and accessors. Judging them by the same method count as a
            // service would flag ordinary Eloquent code.
            $leniency = NodeHelper::isEloquentModel($classLike) ? $modelLeniency : 1.0;

            $lines = NodeHelper::lineSpan($classLike);
            $methods = count(NodeHelper::methods($classLike));
            $publicMethods = count(NodeHelper::publicMethods($classLike));
            $statements = NodeHelper::countStatements($classLike);
            $dependencies = NodeHelper::countDependencies($classLike);
            $collaborators = NodeHelper::countDistinctCallTargets($classLike);

            $signals = [
                $lines > (int) ($maxLines * $leniency),
                $methods > (int) ($maxMethods * $leniency),
                $publicMethods > (int) ($maxPublicMethods * $leniency),
                $statements > (int) ($maxStatements * $leniency),
                $dependencies > $maxDependencies,
                $collaborators > $maxCollaborators,
            ];

            $triggered = count(array_filter($signals));

            if ($triggered < $minSignals) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $classLike,
                message: sprintf(
                    '%s spans %d lines with %d methods (%d public), %d statements, %d injected dependencies and talks to %d distinct collaborators.',
                    $name,
                    $lines,
                    $methods,
                    $publicMethods,
                    $statements,
                    $dependencies,
                    $collaborators,
                ),
                suggestion: 'Group the members that change together and move each group into its own class. '
                    .'Clusters of methods that share the same subset of properties are usually a class trying to '
                    .'get out.',
                confidence: $this->confidenceFrom(55, $signals, 9, 94),
                fingerprint: $name,
                metrics: [
                    'lines' => $lines,
                    'methods' => $methods,
                    'public_methods' => $publicMethods,
                    'statements' => $statements,
                    'dependencies' => $dependencies,
                    'collaborators' => $collaborators,
                    'signals' => $triggered,
                ],
            );
        }
    }
}
