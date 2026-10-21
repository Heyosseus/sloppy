<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\SourceKind;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Support\FileFinder;

/**
 * A Laravel project with a controller, a routes file and a migration.
 */
function laravelTree(): string
{
    return tempProject([
        'composer.json' => '{"require":{"laravel/framework":"^12.0"}}',
        'app/Http/Controllers/OrderController.php' => '<?php class OrderController {}',
        'routes/web.php' => '<?php Route::get("/", fn () => view("welcome"));',
        'database/migrations/2024_01_01_000000_create_orders.php' => '<?php return new class {};',
        'database/seeders/OrderSeeder.php' => '<?php class OrderSeeder {}',
    ]);
}

it('reads routes and migrations as well as the configured paths in a Laravel project', function (): void {
    $root = laravelTree();
    $config = Configuration::fromArray(['paths' => ['app']], $root);

    expect($config->paths())->toBe(['app'])
        ->and((new FileFinder($config))->roots())->toBe(['app', 'routes', 'database/migrations'])
        ->and($config->sourceKinds()->of('routes/web.php'))->toBe(SourceKind::Routes);

    $finder = new FileFinder($config);
    $relative = array_map($finder->relative(...), $finder->find());

    expect($relative)->toBe([
        'app/Http/Controllers/OrderController.php',
        'database/migrations/2024_01_01_000000_create_orders.php',
        'routes/web.php',
    ])
        ->and($finder->covers('routes/api.php'))->toBeTrue()
        ->and($finder->covers('database/seeders/OrderSeeder.php'))->toBeFalse();
});

it('adds nothing when the paths were named for this run', function (): void {
    $root = laravelTree();
    $config = Configuration::fromArray(['paths' => ['app']], $root)->withPaths(['app/Http'])->withMinConfidence(10)->withFailOn(null);

    expect((new FileFinder($config))->roots())->toBe(['app/Http'])
        ->and($config->sourceKinds()->of('routes/web.php'))->toBe(SourceKind::Routes);
});

it('adds nothing outside Laravel, and nothing a project switched off', function (): void {
    $root = laravelTree();

    $vanilla = Configuration::fromArray(['paths' => ['app'], 'framework' => 'none'], $root);
    $switchedOff = Configuration::fromArray(['paths' => ['app'], 'laravel' => ['routes' => [], 'migrations' => []]], $root);

    expect((new FileFinder($vanilla))->roots())->toBe(['app'])
        ->and($vanilla->sourceKinds()->of('routes/web.php'))->toBe(SourceKind::Code)
        ->and((new FileFinder($switchedOff))->roots())->toBe(['app']);
});

it('lists a directory once, however it is spelled', function (): void {
    $root = laravelTree();
    $config = Configuration::fromArray([
        'paths' => ['app', 'routes'],
        'laravel' => ['routes' => ['routes', 'Routes', 'missing'], 'migrations' => ['database/*']],
    ], $root);

    expect((new FileFinder($config))->roots())->toBe(['app', 'routes', 'database/migrations', 'database/seeders'])
        ->and($config->sourceKinds()->of('database/seeders/OrderSeeder.php'))->toBe(SourceKind::Migration);
});

it('keeps the defaults for a key the project left out, and checks what it wrote', function (): void {
    $root = laravelTree();

    expect(Configuration::fromArray(['laravel' => ['routes' => ['web']]], $root)->sourceKinds()->migrations)
        ->toBe(['database/migrations', 'Database/Migrations'])
        ->and(Configuration::fromArray(['laravel' => ['routes' => 5]], $root)->problems)
        ->toBe(['sloppy.laravel.routes must be a list of strings; got 5.'])
        ->and(Configuration::fromArray(['laravel' => ['route' => ['web']]], $root)->unknownSettings[0])
        ->toContain('sloppy.laravel.route is not a setting Sloppy knows');
});
