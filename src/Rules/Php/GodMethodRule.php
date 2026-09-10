<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;

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
            .'the problem: this fires when size, branching and the number of collaborators grow together.';
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

                $lines = NodeHelper::lineSpan($method);
                $complexity = NodeHelper::cyclomaticComplexity($method);
                $statements = NodeHelper::countStatements($method);
                $nesting = NodeHelper::maxNestingDepth($method);
                $calls = NodeHelper::countCalls($method);
                $collaborators = NodeHelper::countDistinctCallTargets($method);

                $signals = [
                    $lines > $maxLines,
                    $complexity > $maxComplexity,
                    $statements > $maxStatements,
                    $nesting > $maxNesting,
                    $calls > $maxCalls,
                    $collaborators > $maxCollaborators,
                ];

                $triggered = count(array_filter($signals));

                // A single measurement can carry a finding on its own only when
                // it is off the scale -- a 200 line method is a god method even
                // if every line is a simple assignment.
                $overwhelming = $lines > $maxLines * 2 || $complexity > $maxComplexity * 2;

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
}
