<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\LaravelRule;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\AssignOp\Coalesce;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Foreach_;

/**
 * SL204 -- a fresh query built and run inside a loop.
 *
 * Distinct from SL203: this is not a relationship being lazily loaded, it is a
 * new query issued per iteration, which no amount of eager loading fixes.
 */
final class QueryInsideLoopRule extends LaravelRule
{
    private const string READ_ADVICE = 'Fetch what the loop needs in one query before it starts -- typically '
        .'%s::whereIn(...)->get()->keyBy(...) -- then look each iteration up in memory.';

    private const string WRITE_ADVICE = 'Collect the rows in the loop and write them once after it -- %s::insert($rows) '
        .'for new rows, or upsert($rows, $uniqueBy) when some may exist. Both skip model events, so keep the loop '
        .'if observers must run.';

    /**
     * Writes that take many rows at once. One of them per chunk is the bulk
     * pattern, not a write per row.
     *
     * @var list<string>
     */
    private const array BULK_WRITES = ['insert', 'insertOrIgnore', 'upsert'];

    public function id(): string
    {
        return 'SL204';
    }

    public function name(): string
    {
        return 'Query Inside Loop';
    }

    public function description(): string
    {
        return 'Flags query builder or Eloquent queries executed inside a loop.';
    }

    public function explanation(): string
    {
        return 'Each iteration issues its own round trip to the database, so cost grows with the size of the '
            .'collection and most of the work is the same query with a different value. One query before the loop, '
            .'keyed by the value the loop varies, usually replaces all of them. Writes are the same: one insert or '
            .'upsert after the loop replaces a create per row, and one bulk write per chunk is not reported.';
    }

    public function category(): Category
    {
        return Category::Performance;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        foreach ($context->classLikes() as $classLike) {
            $className = NodeHelper::shortName($classLike) ?? 'anonymous class';

            /** @var array<string, true> $reported */
            $reported = [];

            foreach (NodeHelper::find($classLike, StaticCall::class) as $call) {
                // `self::find()` on a helper class is not a query, and neither
                // is a static method on a class we can see is not a model.
                if (! LaravelCalls::targetsDatabase($call, $context->index)) {
                    continue;
                }

                $loop = NodeHelper::enclosingLoop($call);

                if (! $loop instanceof Node) {
                    continue;
                }

                // The static call is the start of the chain; whether the chain
                // reaches the database is decided at its far end.
                $chainEnd = NodeHelper::outermostChain($call);

                if (! LaravelCalls::isDatabaseRead($chainEnd) && ! LaravelCalls::isWrite($chainEnd)) {
                    continue;
                }

                $isWrite = ! LaravelCalls::isDatabaseRead($chainEnd);

                if ($this->isMemoized($chainEnd) || ($isWrite && $this->isBulkWrite($chainEnd, $loop))) {
                    continue;
                }

                $subject = NodeHelper::baseName(NodeHelper::staticCallClass($call) ?? 'query');
                $method = NodeHelper::enclosingMethod($call);
                $methodName = $method?->name->toString() ?? 'closure';
                $chain = NodeHelper::chainMethodNames($chainEnd);
                $key = $methodName.':'.$subject.':'.implode('.', $chain);

                if (isset($reported[$key])) {
                    continue;
                }

                $reported[$key] = true;

                yield $this->report(
                    context: $context,
                    at: $call,
                    message: sprintf(
                        '%s::%s() %s %s inside a loop (%s).',
                        $className,
                        $methodName,
                        $isWrite ? 'writes to' : 'queries',
                        $subject,
                        implode('()->', $chain).'()',
                    ),
                    suggestion: sprintf($isWrite ? self::WRITE_ADVICE : self::READ_ADVICE, $subject),
                    confidence: 84,
                    fingerprint: sprintf('%s::%s:%s', $className, $methodName, $subject.'.'.implode('.', $chain)),
                    metrics: [
                        'subject' => $subject,
                        'kind' => $isWrite ? 'write' : 'read',
                        'chain' => implode('->', $chain),
                        'loop_line' => $loop->getStartLine(),
                    ],
                );
            }
        }
    }

    /**
     * Whether the query fills a keyed cache only when the key is missing --
     * `$units[$code] ??= Unit::where(...)->first()`. That runs once per
     * distinct key, not once per iteration.
     */
    /**
     * Whether a bulk write runs once per chunk of a chunked loop --
     * `foreach (array_chunk($rows, 500) as $chunk)` or `foreach ($items->chunk(500) as $batch)`.
     */
    private function isBulkWrite(Node $chainEnd, Node $loop): bool
    {
        if (! NodeHelper::isNameOneOf(NodeHelper::callName($chainEnd), self::BULK_WRITES) || ! $loop instanceof Foreach_) {
            return false;
        }

        $source = $loop->expr;

        return ($source instanceof FuncCall && $source->name instanceof Name && $source->name->toLowerString() === 'array_chunk')
            || ($source instanceof MethodCall && NodeHelper::callName($source) === 'chunk');
    }

    private function isMemoized(Node $chainEnd): bool
    {
        $parent = $chainEnd->getAttribute('parent');

        return $parent instanceof Coalesce
            && $parent->expr === $chainEnd
            && ($parent->var instanceof ArrayDimFetch || $parent->var instanceof PropertyFetch);
    }
}
