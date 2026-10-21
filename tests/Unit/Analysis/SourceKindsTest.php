<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Analyzer;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\SourceKind;
use Heyosseus\Sloppy\Analysis\SourceKinds;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Contracts\Rule;
use Heyosseus\Sloppy\Rules\Laravel\MissingRouteActionRule;
use Heyosseus\Sloppy\Rules\Php\GodMethodRule;
use Heyosseus\Sloppy\Rules\Php\SwallowedExceptionRule;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

it('tells routes and migrations from code by their directory', function (): void {
    $kinds = SourceKinds::laravel();

    expect($kinds->of('routes/web.php'))->toBe(SourceKind::Routes)
        ->and($kinds->of('database/migrations/2024_01_01_000000_create_orders.php'))->toBe(SourceKind::Migration)
        ->and($kinds->of('app/Http/Controllers/OrderController.php'))->toBe(SourceKind::Code)
        ->and($kinds->of('database/seeders/OrderSeeder.php'))->toBe(SourceKind::Code)
        ->and($kinds->of('app/routes.php'))->toBe(SourceKind::Code);
});

it('matches module layouts the way exclude does, at any depth', function (): void {
    $kinds = SourceKinds::laravel();

    expect($kinds->of('Modules/Billing/routes/api.php'))->toBe(SourceKind::Routes)
        ->and($kinds->of('Modules/Billing/Routes/web.php'))->toBe(SourceKind::Routes)
        ->and($kinds->of('Modules/Billing/Database/Migrations/2024_01_01_000000_create_charges.php'))->toBe(SourceKind::Migration)
        ->and($kinds->of('Modules\\Billing\\database\\migrations\\x.php'))->toBe(SourceKind::Migration);
});

it('accepts globs', function (): void {
    $kinds = new SourceKinds(routes: ['packages/*/routes'], migrations: []);

    expect($kinds->of('packages/shop/routes/web.php'))->toBe(SourceKind::Routes)
        ->and($kinds->of('packages/shop/src/Order.php'))->toBe(SourceKind::Code)
        ->and($kinds->directories())->toBe(['packages/*/routes']);
});

it('calls every file code when nothing is configured', function (): void {
    expect((new SourceKinds)->of('routes/web.php'))->toBe(SourceKind::Code)
        ->and((new SourceKinds(routes: [''], migrations: []))->of('routes/web.php'))->toBe(SourceKind::Code);
});

it('lets a rule read code only unless it says otherwise', function (): void {
    expect(SourceKind::Code->admits(new GodMethodRule))->toBeTrue()
        ->and(SourceKind::Migration->admits(new GodMethodRule))->toBeFalse()
        ->and(SourceKind::Routes->admits(new SwallowedExceptionRule))->toBeTrue()
        ->and(SourceKind::Routes->admits(new MissingRouteActionRule))->toBeTrue()
        ->and(SourceKind::Code->admits(new MissingRouteActionRule))->toBeFalse()
        ->and(SourceKind::Migration->admits(plainRule()))->toBeFalse()
        ->and(SourceKind::Code->admits(plainRule()))->toBeTrue();
});

/**
 * A rule that implements the contract directly, as a project's own may.
 */
function plainRule(): Rule
{
    return new class implements Rule
    {
        public function id(): string
        {
            return 'APP900';
        }

        public function name(): string
        {
            return 'Plain';
        }

        public function description(): string
        {
            return 'Plain.';
        }

        public function explanation(): string
        {
            return 'Plain.';
        }

        public function category(): Category
        {
            return Category::Readability;
        }

        public function severity(): Severity
        {
            return Severity::Low;
        }

        public function analyze(AnalysisContext $context): iterable
        {
            return [];
        }
    };
}

it('keeps the size rules out of a migration while the defect rules still read it', function (): void {
    $body = str_repeat("        \$table->string('column_x');\n", 90);
    $migration = <<<PHP
    <?php

    use Illuminate\\Database\\Migrations\\Migration;
    use Illuminate\\Support\\Facades\\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('orders', function (\$table) {
    {$body}        });

            try {
                Schema::table('orders', fn (\$table) => \$table->index('column_x'));
            } catch (\\Throwable \$e) {
            }
        }

        public function down(): void
        {
            Schema::dropIfExists('orders');
        }
    };
    PHP;

    $analyzer = new Analyzer(new Parser, RuleRegistry::withDefaults(), new ScoreCalculator, kinds: SourceKinds::laravel());
    $asMigration = $analyzer->analyzeSources(['database/migrations/2024_01_01_000000_create_orders.php' => $migration]);
    $asCode = (new Analyzer(new Parser, RuleRegistry::withDefaults(), new ScoreCalculator))
        ->analyzeSources(['database/migrations/2024_01_01_000000_create_orders.php' => $migration]);

    expect(ruleIds($asMigration->findings))->toBe(['SL107'])
        ->and(ruleIds($asCode->findings))->toContain('SL101');
});
