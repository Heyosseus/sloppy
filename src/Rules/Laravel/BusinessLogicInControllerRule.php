<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Expr\BinaryOp\Div;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Mul;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * SL201 -- controller actions that decide what the business should do.
 *
 * A controller method containing logic is not automatically wrong: reading a
 * request, calling one collaborator and returning a response is exactly its
 * job. This rule looks for the accumulation that turns an action into the
 * application -- several writes, money arithmetic, a transaction, outbound
 * calls and heavy branching all in one place.
 */
final class BusinessLogicInControllerRule extends BaseRule
{
    public function id(): string
    {
        return 'SL201';
    }

    public function name(): string
    {
        return 'Business Logic In Controller';
    }

    public function description(): string
    {
        return 'Flags controller actions that combine several business concerns: database writes, calculations, transactions, outbound calls and branching.';
    }

    public function explanation(): string
    {
        return 'Logic in a controller can only be exercised through an HTTP request, so it is harder to test, '
            .'impossible to reuse from a command or a job, and it usually mixes transport concerns with domain '
            .'rules. A small action that delegates is not flagged -- this fires when several independent business '
            .'concerns pile up in one method.';
    }

    public function category(): Category
    {
        return Category::Laravel;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::High;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $threshold = max(1, $this->intOption('min_score', 6));
        $minStatements = max(1, $this->intOption('min_statements', 8));

        foreach ($context->classLikes() as $classLike) {
            if (! NodeHelper::isController($classLike)) {
                continue;
            }

            $className = NodeHelper::shortName($classLike) ?? 'controller';

            foreach (NodeHelper::publicMethods($classLike) as $method) {
                if ($method->stmts === null || $method->name->toString() === '__construct') {
                    continue;
                }

                $statements = NodeHelper::countStatements($method);

                // Short actions are the shape we want controllers to have.
                if ($statements < $minStatements) {
                    continue;
                }

                $evidence = $this->weigh($method, $statements);
                $score = array_sum(array_column($evidence, 'weight'));

                if ($score < $threshold) {
                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: $method,
                    message: sprintf(
                        '%s::%s() carries business logic: %s.',
                        $className,
                        $method->name->toString(),
                        implode('; ', array_column($evidence, 'label')),
                    ),
                    suggestion: 'Move the decision-making into a dedicated action, service or job and let the '
                        .'controller validate input, call it once, and turn the result into a response. That makes '
                        .'the logic testable without an HTTP request and reusable from a command or a queue.',
                    confidence: min(90, 52 + $score * 4),
                    fingerprint: $className.'::'.$method->name->toString(),
                    metrics: [
                        'score' => $score,
                        'threshold' => $threshold,
                        'statements' => $statements,
                        'signals' => implode(', ', array_column($evidence, 'label')),
                    ],
                );
            }
        }
    }

    /**
     * Score the business concerns present in one action.
     *
     * @return list<array{label: string, weight: int}>
     */
    private function weigh(ClassMethod $method, int $statements): array
    {
        $evidence = [];

        $writes = $this->countWrites($method);
        $http = $this->countHttp($method);
        $dispatches = $this->countDispatches($method);
        $arithmetic = $this->countArithmetic($method);
        $complexity = NodeHelper::cyclomaticComplexity($method);
        $transaction = $this->hasTransaction($method);

        if ($writes >= 2) {
            $evidence[] = ['label' => sprintf('%d database writes', $writes), 'weight' => $writes >= 4 ? 3 : 2];
        }

        if ($http > 0) {
            $evidence[] = ['label' => sprintf('%d outbound HTTP calls', $http), 'weight' => 3];
        }

        if ($dispatches >= 1) {
            $evidence[] = ['label' => sprintf('%d notifications or queued jobs', $dispatches), 'weight' => $dispatches >= 2 ? 2 : 1];
        }

        if ($arithmetic >= 3) {
            $evidence[] = ['label' => sprintf('%d arithmetic operations', $arithmetic), 'weight' => 2];
        }

        if ($transaction) {
            $evidence[] = ['label' => 'a database transaction', 'weight' => 2];
        }

        if ($complexity > $this->intOption('max_complexity', 8)) {
            $evidence[] = ['label' => sprintf('cyclomatic complexity %d', $complexity), 'weight' => 2];
        }

        if ($statements > $this->intOption('max_statements', 25)) {
            $evidence[] = ['label' => sprintf('%d statements', $statements), 'weight' => 2];
        }

        return $evidence;
    }

    private function countWrites(ClassMethod $method): int
    {
        $count = 0;

        foreach ([MethodCall::class, StaticCall::class] as $type) {
            foreach (NodeHelper::find($method, $type) as $call) {
                if (LaravelCalls::isWrite($call)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countHttp(ClassMethod $method): int
    {
        $count = 0;

        foreach ([StaticCall::class, New_::class, FuncCall::class] as $type) {
            foreach (NodeHelper::find($method, $type) as $node) {
                if (LaravelCalls::isHttpCall($node)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countDispatches(ClassMethod $method): int
    {
        $count = 0;

        foreach ([MethodCall::class, StaticCall::class, FuncCall::class] as $type) {
            foreach (NodeHelper::find($method, $type) as $node) {
                if (LaravelCalls::isDispatch($node)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countArithmetic(ClassMethod $method): int
    {
        $count = 0;

        foreach ([Mul::class, Div::class, Plus::class, Minus::class] as $type) {
            $count += count(NodeHelper::find($method, $type));
        }

        return $count;
    }

    private function hasTransaction(ClassMethod $method): bool
    {
        foreach (NodeHelper::find($method, StaticCall::class) as $call) {
            if (LaravelCalls::isTransaction($call)) {
                return true;
            }
        }

        return false;
    }
}
