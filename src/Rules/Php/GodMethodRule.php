<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;

/**
 * SL101 -- methods that have grown into several methods wearing one name.
 */
final class GodMethodRule extends BaseRule
{
    public function id(): string
    {
        return 'SL101';
    }

    public function name(): string
    {
        return 'God Method';
    }

    public function description(): string
    {
        return 'Flags methods that are excessively long, branchy or chatty across several independent measurements.';
    }

    public function explanation(): string
    {
        return 'A method this size usually holds several unrelated responsibilities, which makes it hard to '
            .'name, hard to test in isolation and hard to change without reading all of it. Length alone is not '
            .'the problem: this fires when size, branching and the number of collaborators grow together. A '
            .'lookup table -- a match or switch mapping constants to constants -- counts as one decision, and its '
            .'rows do not count towards size. A method that is one statement building a value, such as a form or '
            .'table definition, is only reported when it also branches or nests past the limits.';
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
        $maxLines = $this->intOption('max_lines', 80);
        $maxComplexity = $this->intOption('max_complexity', 15);
        $maxStatements = $this->intOption('max_statements', 40);
        $maxNesting = $this->intOption('max_nesting', 4);
        $maxCalls = $this->intOption('max_calls', 25);
        $maxCollaborators = $this->intOption('max_collaborators', 8);
        $minSignals = max(1, $this->intOption('min_signals', 2));

        foreach ($context->classLikes() as $classLike) {
            $className = NodeHelper::shortName($classLike) ?? 'anonymous class';

            foreach (NodeHelper::methods($classLike) as $method) {
                if ($method->stmts === null || $method->stmts === []) {
                    continue;
                }

                [
                    'lines' => $lines,
                    'logic_lines' => $logicLines,
                    'complexity' => $complexity,
                    'statements' => $statements,
                    'nesting' => $nesting,
                    'calls' => $calls,
                    'collaborators' => $collaborators,
                ] = $this->measure($method);

                $signals = [
                    $logicLines > $maxLines,
                    $complexity > $maxComplexity,
                    $statements > $maxStatements,
                    $nesting > $maxNesting,
                    $calls > $maxCalls,
                    $collaborators > $maxCollaborators,
                ];

                $triggered = count(array_filter($signals));

                // One statement building a value -- a form schema, a table
                // definition -- is configuration. However long the chain,
                // there is nothing to follow unless it branches or nests.
                $hasLogic = $complexity > $maxComplexity || $statements > $maxStatements || $nesting > $maxNesting;

                if (! $hasLogic && $this->isDeclarative($method)) {
                    continue;
                }

                // A single measurement can carry a finding on its own only when
                // it is off the scale -- a 200 line method is a god method even
                // if every line is a simple assignment.
                $overwhelming = $logicLines > $maxLines * 2 || $complexity > $maxComplexity * 2;

                if ($triggered < $minSignals && ! $overwhelming) {
                    continue;
                }

                $confidence = $this->confidenceFrom(58, $signals, 9, 96);

                if ($overwhelming) {
                    $confidence = min(96, $confidence + 10);
                }

                yield $this->report(
                    context: $context,
                    at: $method,
                    message: sprintf(
                        '%s::%s() spans %d lines with %d statements, cyclomatic complexity %d, nesting depth %d and %d calls to %d distinct collaborators.',
                        $className,
                        $method->name->toString(),
                        $lines,
                        $statements,
                        $complexity,
                        $nesting,
                        $calls,
                        $collaborators,
                    ),
                    suggestion: 'Identify the distinct jobs this method performs and extract each into its own '
                        .'well-named method or class. Extracting the outermost branches first usually makes the '
                        .'remaining shape obvious.',
                    confidence: $confidence,
                    fingerprint: $className.'::'.$method->name->toString(),
                    metrics: [
                        'lines' => $lines,
                        'statements' => $statements,
                        'complexity' => $complexity,
                        'nesting' => $nesting,
                        'calls' => $calls,
                        'collaborators' => $collaborators,
                        'signals' => $triggered,
                    ],
                );
            }
        }
    }

    /**
     * Whether the body is a single statement that returns or evaluates one
     * expression, such as a fluent builder chain.
     */
    private function isDeclarative(ClassMethod $method): bool
    {
        $stmts = $method->stmts ?? [];

        return count($stmts) === 1 && ($stmts[0] instanceof Return_ || $stmts[0] instanceof Expression);
    }

    /**
     * @return array{lines: int, logic_lines: int, complexity: int, statements: int, nesting: int, calls: int, collaborators: int}
     */
    private function measure(ClassMethod $method): array
    {
        $lines = NodeHelper::lineSpan($method);

        return [
            'lines' => $lines,
            // Rows of a lookup table are data, not logic to follow.
            'logic_lines' => $lines - NodeHelper::lookupTableLines($method),
            'complexity' => NodeHelper::cyclomaticComplexity($method),
            'statements' => NodeHelper::countStatements($method),
            'nesting' => NodeHelper::maxNestingDepth($method),
            'calls' => NodeHelper::countCalls($method),
            'collaborators' => NodeHelper::countDistinctCallTargets($method),
        ];
    }
}
