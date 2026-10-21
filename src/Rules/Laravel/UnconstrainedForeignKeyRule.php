<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\SourceKind;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\LaravelRule;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * SL217 -- a foreign key column with nothing holding it to the other table.
 *
 * `$table->foreignId('user_id')` makes an unsigned big integer and nothing
 * more; the constraint is the `->constrained()` after it, and agents drop it.
 * The column then accepts ids that do not exist, and deleting a user leaves
 * its orders pointing at nobody.
 *
 * A column constrained by a separate `$table->foreign('user_id')` in the same
 * migration is not reported. Only up() is read: a down() that re-adds a
 * column is restoring, not designing.
 */
final class UnconstrainedForeignKeyRule extends LaravelRule
{
    /**
     * @var list<string>
     */
    private const array CONSTRAINTS = ['constrained', 'references', 'on'];

    public function id(): string
    {
        return 'SL217';
    }

    public function name(): string
    {
        return 'Unconstrained Foreign Key';
    }

    public function description(): string
    {
        return 'Flags foreignId() and foreignIdFor() columns that are never given a constraint.';
    }

    public function explanation(): string
    {
        return 'A foreignId() column without constrained() is an ordinary integer: the database accepts an id '
            .'that does not exist and keeps it after the row it pointed at is deleted. The constraint is one '
            .'call, and it is the whole reason to name the column a foreign key.';
    }

    public function category(): Category
    {
        return Category::Laravel;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Low;
    }

    public function sourceKinds(): array
    {
        return [SourceKind::Migration];
    }

    public function analyze(AnalysisContext $context): iterable
    {
        foreach (NodeHelper::find($context->ast(), ClassMethod::class) as $method) {
            if (mb_strtolower($method->name->toString()) !== 'up') {
                continue;
            }

            $calls = NodeHelper::find($method, MethodCall::class);
            $constrained = $this->separatelyConstrained($calls);

            foreach ($calls as $call) {
                $name = NodeHelper::callName($call);

                if (! NodeHelper::isNameOneOf($name, ['foreignId', 'foreignIdFor'])) {
                    continue;
                }

                $chain = NodeHelper::chainMethodNames(NodeHelper::outermostChain($call));

                if (array_intersect(array_map(mb_strtolower(...), $chain), self::CONSTRAINTS) !== []) {
                    continue;
                }

                $column = $this->firstString($call);

                if ($column !== null && isset($constrained[$column])) {
                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: $call,
                    message: $column !== null
                        ? sprintf('%s is created with foreignId() but never constrained to the table it refers to.', $column)
                        : sprintf('A %s() column is created but never constrained to the table it refers to.', $name),
                    suggestion: 'Chain ->constrained() (and ->cascadeOnDelete() or ->nullOnDelete() if that is the intent), '
                        .'so the database refuses ids that do not exist.',
                    confidence: $name === 'foreignIdFor' && $constrained !== [] ? 60 : 78,
                    fingerprint: $column ?? $name.':'.$call->getStartLine(),
                    metrics: ['column' => $column ?? ''],
                );
            }
        }
    }

    /**
     * Columns a `$table->foreign(...)` call constrains.
     *
     * @param  list<MethodCall>  $calls
     * @return array<string, true>
     */
    private function separatelyConstrained(array $calls): array
    {
        $columns = [];

        foreach ($calls as $call) {
            if (! NodeHelper::isNameOneOf(NodeHelper::callName($call), ['foreign'])) {
                continue;
            }

            $argument = (NodeHelper::arguments($call)[0] ?? null)?->value;

            $values = $argument instanceof Array_ ? array_map(static fn (ArrayItem $item): Expr => $item->value, $argument->items) : [$argument];

            foreach ($values as $value) {
                if ($value instanceof String_) {
                    $columns[$value->value] = true;
                }
            }
        }

        return $columns;
    }

    private function firstString(MethodCall $call): ?string
    {
        $value = (NodeHelper::arguments($call)[0] ?? null)?->value;

        return $value instanceof String_ ? $value->value : null;
    }
}
