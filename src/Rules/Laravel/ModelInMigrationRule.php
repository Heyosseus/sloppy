<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\SourceKind;
use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Rules\LaravelRule;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;

/**
 * SL216 -- a migration that works through an Eloquent model.
 *
 * A migration runs against the schema as it was on the day it was written,
 * forever. A model describes the schema as it is today. `User::query()->
 * update(...)` in a backfill works now; a year later the model has a global
 * scope, a cast or a renamed column, and a fresh `migrate` on a new machine
 * fails halfway through.
 *
 * `foreignIdFor(User::class)` names the class without querying through it,
 * and is not reported.
 */
final class ModelInMigrationRule extends LaravelRule
{
    public function id(): string
    {
        return 'SL216';
    }

    public function name(): string
    {
        return 'Model In Migration';
    }

    public function description(): string
    {
        return 'Flags a migration that calls or constructs an Eloquent model.';
    }

    public function explanation(): string
    {
        return 'Migrations are replayed from the beginning on every new database. A model changes with the code -- '
            .'scopes, casts, events, renamed columns -- so a migration written against it breaks long after it '
            .'shipped, on the machine of whoever runs migrate next.';
    }

    public function category(): Category
    {
        return Category::Laravel;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function sourceKinds(): array
    {
        return [SourceKind::Migration];
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $reported = [];

        foreach ([...NodeHelper::find($context->ast(), StaticCall::class), ...NodeHelper::find($context->ast(), New_::class)] as $node) {
            $model = $node->class instanceof Name ? $node->class->toString() : null;

            if ($model === null || isset($reported[$model]) || ! $this->isModel($model, $context->index)) {
                continue;
            }

            $reported[$model] = true;
            $short = NodeHelper::baseName($model);

            yield $this->report(
                context: $context,
                at: $node,
                message: sprintf('This migration works through the %s model, which will have changed by the next time it runs.', $short),
                suggestion: sprintf(
                    'Use the query builder on the table instead -- DB::table(\'%s\') -- so the migration depends only on the schema it was written for.',
                    $this->tableGuess($short),
                ),
                confidence: $context->index->isEloquentModel($model) ? 88 : 75,
                fingerprint: $model,
                metrics: ['model' => $model],
            );
        }
    }

    /**
     * A model the index knows, or a class in App\Models when the models are
     * not among the analysed paths.
     */
    private function isModel(string $class, ProjectIndex $index): bool
    {
        return $index->isEloquentModel($class)
            || (! $index->class($class) instanceof ClassSummary && str_starts_with($class, 'App\\Models\\'));
    }

    /**
     * `OrderItem` -> `order_items`, Laravel's own convention, for the example
     * in the suggestion only.
     */
    private function tableGuess(string $model): string
    {
        $snake = mb_strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $model));

        return str_ends_with($snake, 'y') && ! preg_match('/[aeiou]y$/', $snake)
            ? substr($snake, 0, -1).'ies'
            : (str_ends_with($snake, 's') ? $snake.'es' : $snake.'s');
    }
}
