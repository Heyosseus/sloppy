<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Rules\Laravel\IrreversibleMigrationRule;
use Heyosseus\Sloppy\Rules\Laravel\ModelInMigrationRule;
use Heyosseus\Sloppy\Rules\Laravel\UnconstrainedForeignKeyRule;

const MIGRATION = 'database/migrations/2024_01_01_000000_create_orders.php';

/**
 * An anonymous migration class with these bodies.
 */
function migration(string $up, ?string $down = null): string
{
    $downMethod = $down === null ? '' : "\n\n    public function down(): void\n    {\n{$down}\n    }";

    return <<<PHP
    use Illuminate\\Database\\Migrations\\Migration;
    use Illuminate\\Database\\Schema\\Blueprint;
    use Illuminate\\Support\\Facades\\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
    {$up}
        }{$downMethod}
    };
    PHP;
}

// ---- SL215 Irreversible Migration -----------------------------------------

it('flags a schema change with no way back', function (?string $down, string $problem, int $confidence): void {
    $found = findings(new IrreversibleMigrationRule, migration(
        "        Schema::create('orders', fn (Blueprint \$table) => \$table->id());",
        $down,
    ), MIGRATION);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL215')
        ->and($found[0]->message)->toBe("Migration 2024_01_01_000000_create_orders changes the schema in up() but {$problem}.")
        ->and($found[0]->confidence)->toBe($confidence);
})->with([
    'no down()' => [null, 'has no down()', 90],
    'an empty down()' => ['        //', 'has an empty down()', 90],
    'a down() that does nothing useful' => ["        logger('rolled back');", 'has a down() that touches neither the schema nor the database', 70],
]);

it('accepts a down() that undoes, delegates or refuses', function (string $down): void {
    expect(findings(new IrreversibleMigrationRule, migration(
        "        Schema::create('orders', fn (Blueprint \$table) => \$table->id());",
        $down,
    ), MIGRATION))->toBeEmpty();
})->with([
    'drops the table' => ["        Schema::dropIfExists('orders');"],
    'restores data' => ["        DB::table('orders')->delete();"],
    'calls a helper' => ['        $this->dropEverything();'],
    'throws' => ["        throw new RuntimeException('This migration cannot be reversed.');"],
]);

it('leaves a data-only migration alone, and names a named class by its name', function (): void {
    expect(findings(new IrreversibleMigrationRule, migration("        DB::table('orders')->update(['status' => 'open']);"), MIGRATION))
        ->toBeEmpty();

    $found = findings(new IrreversibleMigrationRule, <<<'PHP'
    class CreateOrdersTable extends Illuminate\Database\Migrations\Migration
    {
        public function up()
        {
            Schema::table('orders', fn ($table) => $table->string('note'));
        }
    }
    PHP, MIGRATION);

    expect($found[0]->message)->toStartWith('Migration CreateOrdersTable');
});

// ---- SL216 Model In Migration ---------------------------------------------

it('flags a migration that queries through a model, once per model', function (): void {
    $found = findingsAcross(new ModelInMigrationRule, [
        'app/Models/OrderItem.php' => <<<'PHP'
        namespace App\Models;

        class OrderItem extends \Illuminate\Database\Eloquent\Model {}
        PHP,
        MIGRATION => "use App\\Models\\OrderItem;\n".migration(<<<'PHP'
                OrderItem::query()->update(['status' => 'open']);
                OrderItem::where('id', 1)->delete();
                $user = new App\Models\User();
        PHP, "        Schema::dropIfExists('orders');"),
    ]);

    expect($found)->toHaveCount(2)
        ->and($found[0]->ruleId)->toBe('SL216')
        ->and($found[0]->metrics['model'])->toBe('App\Models\OrderItem')
        ->and($found[0]->confidence)->toBe(88)
        ->and($found[1]->metrics['model'])->toBe('App\Models\User')
        ->and($found[1]->confidence)->toBe(75)
        ->and($found[1]->suggestion)->toContain("DB::table('users')");
});

it('guesses the table the way Laravel names it', function (string $model, string $table): void {
    $found = findings(new ModelInMigrationRule, migration("        App\\Models\\{$model}::query()->delete();"), MIGRATION);

    expect($found[0]->suggestion)->toContain("DB::table('{$table}')");
})->with([
    ['OrderItem', 'order_items'],
    ['Category', 'categories'],
    ['Survey', 'surveys'],
    ['Address', 'addresses'],
]);

it('leaves the schema, the query builder and foreignIdFor alone', function (): void {
    expect(findings(new ModelInMigrationRule, migration(<<<'PHP'
            Schema::create('orders', function (Blueprint $table) {
                $table->foreignIdFor(App\Models\User::class)->constrained();
            });
            DB::table('orders')->update(['status' => 'open']);
            Carbon\Carbon::now();
    PHP), MIGRATION))->toBeEmpty();
});

// ---- SL217 Unconstrained Foreign Key --------------------------------------

it('flags foreign key columns with no constraint', function (): void {
    $found = findings(new UnconstrainedForeignKeyRule, migration(<<<'PHP'
            Schema::create('orders', function (Blueprint $table) {
                $table->foreignId('user_id');
                $table->foreignId('team_id')->nullable()->index();
                $table->foreignIdFor(App\Models\Coupon::class);
            });
    PHP), MIGRATION);

    expect(ruleIds($found))->toBe(['SL217', 'SL217', 'SL217'])
        ->and($found[0]->message)->toBe('user_id is created with foreignId() but never constrained to the table it refers to.')
        ->and($found[2]->message)->toBe('A foreignIdFor() column is created but never constrained to the table it refers to.')
        ->and($found[2]->confidence)->toBe(78);
});

it('accepts constrained(), a separate foreign() and anything in down()', function (): void {
    expect(findings(new UnconstrainedForeignKeyRule, migration(<<<'PHP'
            Schema::create('orders', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('team_id')->nullable()->constrained('teams');
                $table->foreignId('owner_id');
                $table->foreignId('a_id');
                $table->foreignId('b_id');
                $table->foreign('owner_id')->references('id')->on('users');
                $table->foreign(['a_id', 'b_id'])->references(['id', 'b'])->on('pairs');
            });
    PHP, <<<'PHP'
            Schema::table('orders', fn (Blueprint $table) => $table->foreignId('legacy_id'));
    PHP), MIGRATION))->toBeEmpty();
});

it('is less sure of foreignIdFor when the migration constrains columns separately', function (): void {
    $found = findings(new UnconstrainedForeignKeyRule, migration(<<<'PHP'
            Schema::create('orders', function (Blueprint $table) {
                $table->foreignIdFor(App\Models\User::class);
                $table->foreign('user_id')->references('id')->on('users');
            });
    PHP), MIGRATION);

    expect($found)->toHaveCount(1)
        ->and($found[0]->confidence)->toBe(60);
});

it('reads migrations only', function (): void {
    expect(findings(new UnconstrainedForeignKeyRule, migration("        \$table->foreignId('user_id');"), 'app/Support/Schema.php'))
        ->toBeEmpty()
        ->and(findings(new IrreversibleMigrationRule, migration("        Schema::create('x', fn () => null);"), 'app/Support/Schema.php'))
        ->toBeEmpty();
});
