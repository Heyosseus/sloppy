<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\SourceKind;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\LaravelRule;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * SL215 -- a migration that changes the schema and cannot undo it.
 *
 * The scaffold always writes a `down()`. Agents writing a migration by hand
 * leave it out, leave it empty, or fill it with a comment, and nobody notices
 * until `migrate:rollback` reports success and changes nothing -- the next
 * migration then fails against a table that should not be there.
 *
 * A team that never rolls back can turn the rule off.
 */
final class IrreversibleMigrationRule extends LaravelRule
{
    public function id(): string
    {
        return 'SL215';
    }

    public function name(): string
    {
        return 'Irreversible Migration';
    }

    public function description(): string
    {
        return 'Flags a migration whose up() changes the schema while its down() is missing, empty, or touches neither the schema nor the database.';
    }

    public function explanation(): string
    {
        return '`migrate:rollback` runs down() and reports success whatever it did. A down() that does nothing '
            .'leaves the schema where up() put it, so the rollback that was supposed to make a bad deploy safe '
            .'leaves the database out of step with the code.';
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
        foreach (NodeHelper::find($context->ast(), Class_::class) as $class) {
            $up = $class->getMethod('up');

            if (! $up instanceof ClassMethod || ! $this->callsOn($up, ['Schema'])) {
                continue;
            }

            $down = $class->getMethod('down');
            $name = $class->name?->toString() ?? basename($context->relativePath(), '.php');

            [$problem, $confidence] = match (true) {
                ! $down instanceof ClassMethod => ['has no down()', 90],
                NodeHelper::countStatements($down) === 0 => ['has an empty down()', 90],
                ! $this->undoes($down) => ['has a down() that touches neither the schema nor the database', 70],
                default => [null, 0],
            };

            if ($problem === null) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $down ?? $up,
                message: sprintf('Migration %s changes the schema in up() but %s.', $name, $problem),
                suggestion: 'Write the inverse of up() in down(): drop what it created, re-add what it removed. If this '
                    .'change genuinely cannot be undone, throw from down() so a rollback stops instead of pretending.',
                confidence: $confidence,
                fingerprint: $name,
            );
        }
    }

    /**
     * Whether a down() does something a rollback could rely on: touches the
     * schema or the database, hands off to a method of its own, or throws to
     * say it cannot be undone.
     */
    private function undoes(ClassMethod $down): bool
    {
        if ($this->callsOn($down, ['Schema', 'DB']) || NodeHelper::find($down, Throw_::class) !== []) {
            return true;
        }

        foreach (NodeHelper::find($down, MethodCall::class) as $call) {
            if ($call->var instanceof Variable && $call->var->name === 'this') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a method makes a static call on one of these facades.
     *
     * @param  list<string>  $facades
     */
    private function callsOn(Node $method, array $facades): bool
    {
        foreach (NodeHelper::find($method, StaticCall::class) as $call) {
            $class = NodeHelper::staticCallClass($call);

            if ($class !== null && in_array(NodeHelper::baseName($class), $facades, true)) {
                return true;
            }
        }

        return false;
    }
}
