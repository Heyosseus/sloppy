<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Rules\Laravel\DestructiveGetRouteRule;
use Heyosseus\Sloppy\Rules\Laravel\LogicInRouteClosureRule;
use Heyosseus\Sloppy\Rules\Laravel\MissingRouteActionRule;

/**
 * A controller with `index` and `show`, under the routes file being tested.
 *
 * @return array<string, string>
 */
function withOrderController(string $routes, string $controller = ''): array
{
    return [
        'app/Http/Controllers/Controller.php' => <<<'PHP'
        namespace App\Http\Controllers;

        abstract class Controller
        {
        }
        PHP,
        'app/Http/Controllers/OrderController.php' => $controller !== '' ? $controller : <<<'PHP'
        namespace App\Http\Controllers;

        class OrderController extends Controller
        {
            public function index() { return []; }

            public function show($order) { return $order; }

            protected function export() { return []; }
        }
        PHP,
        'routes/web.php' => $routes,
    ];
}

// ---- SL211 Missing Route Action -------------------------------------------

it('flags a route to a method the controller does not have', function (): void {
    $found = findingsAcross(new MissingRouteActionRule, withOrderController(<<<'PHP'
    use App\Http\Controllers\OrderController;
    use Illuminate\Support\Facades\Route;

    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/export', [OrderController::class, 'exportCsv'])->name('orders.export');
    PHP));

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL211')
        ->and($found[0]->location->relativePath)->toBe('routes/web.php')
        ->and($found[0]->location->line)->toBe(7)
        ->and($found[0]->message)->toBe('GET /orders/export calls OrderController::exportCsv(), which OrderController does not have.')
        ->and($found[0]->fingerprint)->toBe('App\Http\Controllers\OrderController::exportCsv');
});

it('suggests the method a typo was reaching for', function (): void {
    $found = findingsAcross(new MissingRouteActionRule, withOrderController(<<<'PHP'
    use App\Http\Controllers\OrderController;

    Route::middleware('auth')->get('/orders/{order}', [OrderController::class, 'shwo']);
    PHP));

    expect($found)->toHaveCount(1)
        ->and($found[0]->suggestion)->toContain('OrderController::show()');
});

it('reads the string forms: a fully qualified X@method and a controller group', function (): void {
    $found = findingsAcross(new MissingRouteActionRule, withOrderController(<<<'PHP'
    use App\Http\Controllers\OrderController;

    Route::get('/a', 'App\Http\Controllers\OrderController@missingA');
    Route::get('/b', 'OrderController@missingB');

    Route::controller(OrderController::class)->prefix('orders')->group(function () {
        Route::get('/', 'index');
        Route::post('/c', 'missingC');
    });
    PHP));

    expect(array_map(static fn (Heyosseus\Sloppy\Analysis\Finding $finding): string|int|float|bool => $finding->metrics['method'], $found))->toBe(['missingA', 'missingC'])
        ->and($found[1]->confidence)->toBeLessThan($found[0]->confidence);
});

it('flags an invokable controller with no __invoke', function (): void {
    $found = findingsAcross(new MissingRouteActionRule, withOrderController(<<<'PHP'
    Route::post('/orders', App\Http\Controllers\OrderController::class);
    PHP));

    expect($found)->toHaveCount(1)
        ->and($found[0]->message)->toContain('has no __invoke() method');
});

it('counts a protected method as missing, since the router cannot call it', function (): void {
    expect(findingsAcross(new MissingRouteActionRule, withOrderController(<<<'PHP'
    Route::get('/export', [App\Http\Controllers\OrderController::class, 'export']);
    PHP)))->toHaveCount(1);
});

it('finds methods on parents and traits, whatever their case', function (): void {
    $files = withOrderController(<<<'PHP'
    use App\Http\Controllers\OrderController;

    Route::get('/a', [OrderController::class, 'fromParent']);
    Route::get('/b', [OrderController::class, 'fromTrait']);
    Route::get('/c', [OrderController::class, 'INDEX']);
    Route::get('/d', [OrderController::class, 'middleware']);
    PHP, <<<'PHP'
    namespace App\Http\Controllers;

    class OrderController extends BaseOrders
    {
        use Exports;

        public function index() { return []; }
    }
    PHP);

    $files['app/Http/Controllers/BaseOrders.php'] = <<<'PHP'
    namespace App\Http\Controllers;

    abstract class BaseOrders extends \Illuminate\Routing\Controller
    {
        public function fromParent() { return []; }
    }
    PHP;
    $files['app/Http/Controllers/Exports.php'] = <<<'PHP'
    namespace App\Http\Controllers;

    trait Exports
    {
        public function fromTrait() { return []; }
    }
    PHP;

    expect(findingsAcross(new MissingRouteActionRule, $files))->toBeEmpty();
});

it('stays quiet when it cannot see the whole controller', function (string $controller): void {
    expect(findingsAcross(new MissingRouteActionRule, withOrderController(<<<'PHP'
    Route::get('/a', [App\Http\Controllers\OrderController::class, 'anything']);
    Route::get('/b', [Vendor\Package\Http\SomeController::class, 'anything']);
    PHP, $controller)))->toBeEmpty();
})->with([
    'a parent outside the analysed paths' => [<<<'PHP'
        namespace App\Http\Controllers;

        class OrderController extends \Vendor\Package\BaseController {}
        PHP],
    'a trait outside the analysed paths' => [<<<'PHP'
        namespace App\Http\Controllers;

        class OrderController { use \Vendor\Package\Actions; }
        PHP],
    'a __call that forwards anything' => [<<<'PHP'
        namespace App\Http\Controllers;

        class OrderController { public function __call($name, $arguments) {} }
        PHP],
]);

it('does not read routes outside a routes file, or anything that is not a route', function (): void {
    $routes = <<<'PHP'
    use App\Http\Controllers\OrderController;

    Route::resource('orders', OrderController::class);
    Route::get('/x', [OrderController::class]);
    Route::get('/y', [$controller, 'missing']);
    $router->get('/z', [OrderController::class, 'missing']);
    Cache::get('/k', [OrderController::class, 'missing']);
    Route::get('/w');
    PHP;

    $files = withOrderController($routes);
    $files['app/Providers/RouteServiceProvider.php'] = "Route::get('/x', [App\\Http\\Controllers\\OrderController::class, 'missing']);";

    expect(findingsAcross(new MissingRouteActionRule, $files))->toBeEmpty();
});

// ---- SL212 Logic In Route Closure -----------------------------------------

it('flags a route closure that queries and validates', function (): void {
    $found = findings(new LogicInRouteClosureRule, <<<'PHP'
    use App\Models\Order;
    use Illuminate\Http\Request;

    Route::post('/orders', function (Request $request) {
        $data = $request->validate(['total' => 'required|integer']);

        return Order::where('user_id', $request->user()->id)->get();
    });
    PHP, 'routes/web.php');

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL212')
        ->and($found[0]->message)->toBe('The closure for POST /orders queries the database and validates input.')
        ->and($found[0]->severity)->toBe(Severity::Medium);
});

it('lowers a closure with a single concern to Low', function (): void {
    $found = findings(new LogicInRouteClosureRule, <<<'PHP'
    Route::get('/stats', fn () => DB::table('orders')->count());
    PHP, 'routes/api.php');

    expect($found)->toHaveCount(1)
        ->and($found[0]->severity)->toBe(Severity::Low)
        ->and($found[0]->message)->toContain('queries the database');
});

it('flags a long closure even when it does nothing it can name', function (): void {
    $found = findings(new LogicInRouteClosureRule(['max_statements' => 2]), <<<'PHP'
    Route::get('/report', function () {
        $a = 1;
        $b = 2;
        $c = 3;

        return view('report', compact('a', 'b', 'c'));
    });
    PHP, 'routes/web.php');

    expect($found)->toHaveCount(1)
        ->and($found[0]->message)->toContain('runs 4 statements');
});

it('leaves closures that return a view or a redirect alone', function (): void {
    expect(findings(new LogicInRouteClosureRule, <<<'PHP'
    Route::get('/', fn () => view('welcome'));
    Route::get('/home', function () {
        return redirect('/dashboard');
    });
    Route::get('/me', fn (Illuminate\Http\Request $request) => $request->user());
    PHP, 'routes/web.php'))->toBeEmpty();
});

// ---- SL213 Destructive GET Route ------------------------------------------

it('flags a GET routed to an action that changes data', function (): void {
    $found = findings(new DestructiveGetRouteRule, <<<'PHP'
    Route::get('/orders/{order}', [OrderController::class, 'destroy']);
    PHP, 'routes/web.php');

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL213')
        ->and($found[0]->confidence)->toBe(90)
        ->and($found[0]->suggestion)->toContain('Route::delete()')
        ->and($found[0]->suggestion)->toContain("@method('DELETE')");
});

it('flags a GET whose URI says it deletes, and is less sure of a prefix', function (): void {
    $found = findings(new DestructiveGetRouteRule, <<<'PHP'
    Route::get('/orders/{order}/delete', fn () => null);
    Route::get('/account/delete-confirm', fn () => view('confirm'));
    Route::get('/posts/{post}/store', [PostController::class, 'store']);
    PHP, 'routes/web.php');

    $byUri = [];

    foreach ($found as $finding) {
        $byUri[$finding->metrics['uri']] = $finding;
    }

    expect($found)->toHaveCount(3)
        ->and($byUri['/orders/{order}/delete']->confidence)->toBe(85)
        ->and($byUri['/orders/{order}/delete']->severity)->toBe(Severity::High)
        ->and($byUri['/account/delete-confirm']->confidence)->toBe(65)
        ->and($byUri['/account/delete-confirm']->severity)->toBe(Severity::Medium)
        ->and($byUri['/posts/{post}/store']->suggestion)->toContain('Route::post()')
        ->and($byUri['/posts/{post}/store']->suggestion)->not->toContain('@method');
});

it('leaves the same URIs alone on other verbs, and harmless GETs', function (): void {
    expect(findings(new DestructiveGetRouteRule, <<<'PHP'
    Route::delete('/orders/{order}', [OrderController::class, 'destroy']);
    Route::post('/orders/{order}/delete', [OrderController::class, 'destroy']);
    Route::get('/orders/{delete}', [OrderController::class, 'show']);
    Route::get('/orders/{order}/edit', [OrderController::class, 'edit']);
    Route::get('/deleted-orders', [OrderController::class, 'trashed']);
    Route::get($uri, [OrderController::class, 'index']);
    PHP, 'routes/web.php'))->toBeEmpty();
});
