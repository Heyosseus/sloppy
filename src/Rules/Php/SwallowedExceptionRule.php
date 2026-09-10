<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Nop;

/**
 * SL107 -- catch blocks where the failure goes nowhere.
 *
 * Not every catch that returns a fallback is wrong, so the rule looks for
 * catches that do nothing observable at all: no rethrow, no logging, no
 * reporting, no state change. Catching a specific exception type is treated as
 * more deliberate than catching `Throwable`, and scores lower confidence.
 */
final class SwallowedExceptionRule extends BaseRule
{
    /**
     * @var list<string>
     */
    private const array BROAD_TYPES = [
        'Throwable',
        'Exception',
        'Error',
    ];

    public function id(): string
    {
        return 'SL107';
    }

    public function name(): string
    {
        return 'Swallowed Exception';
    }

    public function description(): string
    {
        return 'Flags catch blocks that neither rethrow, report, log nor otherwise react to the failure they caught.';
    }

    public function explanation(): string
    {
        return 'A catch block that does nothing observable turns a failure into a silent wrong answer: the caller '
            .'sees null or false and cannot tell a genuine empty result from a crash, and nothing is recorded for '
            .'anyone to investigate later. Catches that log, report, rethrow or record state are not flagged.';
    }

    public function category(): Category
    {
        return Category::ErrorHandling;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::High;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        foreach ($context->classLikes() as $classLike) {
            $className = NodeHelper::shortName($classLike) ?? 'anonymous class';

            /** @var array<string, int> $seen */
            $seen = [];

            foreach (NodeHelper::find($classLike, Catch_::class) as $catch) {
                if ($this->handlesFailure($catch)) {
                    continue;
                }

                $types = array_map(
                    static fn (Node\Name $name): string => NodeHelper::baseName($name->toString()),
                    $catch->types,
                );

                $broad = array_intersect($types, self::BROAD_TYPES) !== [];
                $empty = $this->meaningfulStatements($catch) === [];
                $method = NodeHelper::enclosingMethod($catch);
                $methodName = $method?->name->toString() ?? 'closure';

                // Two identical swallowing catches in one method are two
                // findings, so the fingerprint has to tell them apart.
                $key = sprintf('%s::%s:%s', $className, $methodName, implode('|', $types));
                $ordinal = $seen[$key] = ($seen[$key] ?? 0) + 1;

                yield $this->report(
                    context: $context,
                    at: $catch,
                    message: sprintf(
                        '%s::%s() catches %s and %s.',
                        $className,
                        $methodName,
                        implode('|', $types === [] ? ['Throwable'] : $types),
                        $empty ? 'does nothing at all' : 'returns without recording the failure',
                    ),
                    suggestion: 'Do at least one of: log or report the exception, rethrow it wrapped in a '
                        .'domain-specific type, or return a result the caller can distinguish from success. If the '
                        .'failure genuinely is expected and unremarkable, say so in a comment so the next reader '
                        .'knows it was a decision.',
                    confidence: $this->confidence($empty, $broad),
                    fingerprint: $ordinal === 1 ? $key : $key.'#'.$ordinal,
                    metrics: [
                        'caught' => implode('|', $types),
                        'empty_body' => $empty,
                        'broad_type' => $broad,
                    ],
                );
            }
        }
    }

    private function confidence(bool $empty, bool $broad): int
    {
        if ($empty) {
            return $broad ? 96 : 92;
        }

        // A narrow catch returning a fallback is a defensible pattern often
        // enough that we should not be strident about it.
        return $broad ? 84 : 66;
    }

    /**
     * Whether the catch body does anything the outside world can observe.
     */
    private function handlesFailure(Catch_ $catch): bool
    {
        foreach ($this->meaningfulStatements($catch) as $statement) {
            if ($statement instanceof Echo_) {
                return true;
            }

            if (NodeHelper::findFirst($statement, Throw_::class) instanceof Throw_) {
                return true;
            }

            foreach ([MethodCall::class, NullsafeMethodCall::class, StaticCall::class, FuncCall::class] as $callType) {
                if (NodeHelper::findFirst($statement, $callType) instanceof Node) {
                    return true;
                }
            }

            // Recording the failure on the object or a static counts as
            // handling: something later can act on it.
            foreach (NodeHelper::find($statement, Assign::class) as $assign) {
                if ($assign->var instanceof PropertyFetch || $assign->var instanceof StaticPropertyFetch) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<Node\Stmt>
     */
    private function meaningfulStatements(Catch_ $catch): array
    {
        return array_values(array_filter(
            $catch->stmts,
            static fn (Node\Stmt $statement): bool => ! $statement instanceof Nop,
        ));
    }
}
