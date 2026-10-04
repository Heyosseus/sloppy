<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Rules\Architecture\AbstractionInflationRule;
use Heyosseus\Sloppy\Rules\Architecture\BoundaryViolationRule;
use Heyosseus\Sloppy\Rules\Architecture\EmptyWrapperClassRule;
use Heyosseus\Sloppy\Rules\Architecture\ForbiddenCapabilityRule;
use Heyosseus\Sloppy\Rules\Architecture\LayerViolationRule;
use Heyosseus\Sloppy\Rules\Architecture\SingleUseAbstractionRule;
use Heyosseus\Sloppy\Rules\Laravel\DirectExternalApiRule;

const LAYERED_APP = [
    'app/Http/Controllers/OrderController.php' => '<?php namespace App\Http\Controllers;
        use App\Models\Order;
        use App\Repositories\OrderRepository;
        class OrderController {
            public function __construct(private OrderRepository $orders) {}
            public function index() { Order::count(); return Order::where("paid", true)->get(); }
            public function show(int $id) { return Order::find($id); }
            public function store() { Order::create([]); }
            public function config() { return env("X"); }
        }',
    'app/Models/Order.php' => '<?php namespace App\Models; class Order extends \Illuminate\Database\Eloquent\Model {}',
    'app/Repositories/OrderRepository.php' => '<?php namespace App\Repositories; class OrderRepository {}',
];

it('reports a dependency the role may not have, where the class first names it', function (): void {
    $findings = findingsUnder(new LayerViolationRule, LAYERED_APP, ['preset' => 'service-repository']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('SL304')
        ->and($findings[0]->location->line)->toBe(5)
        ->and($findings[0]->message)->toBe('OrderController (controller) depends on App\Repositories\OrderRepository (repository), but classes in the controller role may not depend on repository.')
        ->and($findings[0]->suggestion)->toContain('A controller calls a service, and the service calls the repository.')
        ->and($findings[0]->suggestion)->toContain('sloppy.architecture.policies.controller (preset service-repository)')
        ->and($findings[0]->metrics)->toBe(['role' => 'controller', 'depends_on' => 'App\Repositories\OrderRepository', 'depends_on_role' => 'repository'])
        ->and($findings[0]->fingerprint)->toBe('OrderController->App\Repositories\OrderRepository');
});

it('reports a framework dependency forbidden by name, with no role on the other side', function (): void {
    $files = ['app/Domain/Billing/Invoice.php' => '<?php namespace App\Domain\Billing; class Invoice { public function total(): \Illuminate\Support\Collection { return collect(); } }'];

    $findings = findingsUnder(new LayerViolationRule, $files, ['preset' => 'hexagonal']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toBe('Invoice (domain) depends on Illuminate\Support\Collection, but classes in the domain role may not depend on Illuminate\*.');
});

it('stays silent where nothing is declared, and for classes with no policy', function (): void {
    expect(findingsUnder(new LayerViolationRule, LAYERED_APP, []))->toBe([])
        ->and(findingsUnder(new LayerViolationRule, LAYERED_APP, ['preset' => 'service-repository', 'policies' => ['controller' => ['may_not' => ['db']]]]))->toBe([]);
});

it('reports each forbidden capability once per method', function (): void {
    $findings = findingsUnder(new ForbiddenCapabilityRule, LAYERED_APP, ['policies' => ['controller' => ['may_not' => ['db', 'env', 'http']]]]);

    expect(array_map(static fn (Heyosseus\Sloppy\Analysis\Finding $finding): string => $finding->message, $findings))->toBe([
        'OrderController::index() queries the database (Order::count()), which classes in the controller role may not do.',
        'OrderController::show() queries the database (Order::find()), which classes in the controller role may not do.',
        'OrderController::store() writes to the database (Order::create()), which classes in the controller role may not do.',
        'OrderController::config() reads the environment (env()), which classes in the controller role may not do.',
    ])
        ->and($findings[0]->metrics)->toBe(['role' => 'controller', 'capability' => 'db.read', 'evidence' => 'Order::count()'])
        ->and($findings[0]->suggestion)->toBe('Move it into a class whose role allows it and call that from here (sloppy.php).');
});

it('reports an injected capability against the class itself', function (): void {
    $files = ['app/Domain/Pricing.php' => '<?php namespace App\Domain; class Pricing { public function __construct(private \Illuminate\Contracts\Foundation\Application $app) {} }'];

    $findings = findingsUnder(new ForbiddenCapabilityRule, $files, ['preset' => 'ddd']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toStartWith('Pricing::__construct() resolves from the service container (injects Illuminate\Contracts\Foundation\Application)')
        ->and($findings[0]->suggestion)->toStartWith('The domain is called; it does not call out.');
});

it('leaves outbound HTTP to SL208, which reports it for any role that forbids it', function (): void {
    $files = ['app/Domain/Rates.php' => '<?php namespace App\Domain; class Rates { public function fetch() { return \Illuminate\Support\Facades\Http::get("https://x.test"); } }'];
    $architecture = ['preset' => 'ddd'];

    $sl208 = findingsUnder(new DirectExternalApiRule, $files, $architecture);

    expect(findingsUnder(new ForbiddenCapabilityRule, $files, $architecture))->toBe([])
        ->and($sl208)->toHaveCount(1)
        ->and($sl208[0]->message)->toContain('directly from a class in the domain role')
        ->and(findingsUnder(new DirectExternalApiRule, $files, []))->toBe([]);
});

it('reports a module reaching into another module\'s internals, and nothing through the public surface', function (): void {
    $files = [
        'app/Modules/Billing/InvoiceService.php' => '<?php namespace App\Modules\Billing;
            use App\Modules\Shipping\Internal\RateTable;
            use App\Modules\Shipping\Contracts\Rates;
            class InvoiceService {
                public function __construct(private Rates $rates) {}
                public function total() { return new RateTable(); }
            }',
        'app/Modules/Shipping/Internal/RateTable.php' => '<?php namespace App\Modules\Shipping\Internal; class RateTable {}',
        'app/Http/Controllers/RatesController.php' => '<?php namespace App\Http\Controllers; class RatesController { public function __invoke() { return new \App\Modules\Shipping\Internal\RateTable(); } }',
    ];

    $findings = findingsUnder(new BoundaryViolationRule, $files, ['preset' => 'modular']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('SL306')
        ->and($findings[0]->location->line)->toBe(6)
        ->and($findings[0]->message)->toBe('InvoiceService uses App\Modules\Shipping\Internal\RateTable, but Internal\RateTable is internal to the Shipping module, and InvoiceService is in Billing.')
        ->and($findings[0]->suggestion)->toBe('Go through the module\'s public surface (Contracts\*, Events\*, Data\*, Enums\*) -- add a contract or an event there if what you need is missing (preset modular).')
        ->and($findings[0]->metrics)->toBe(['module' => 'Billing', 'target_module' => 'Shipping', 'depends_on' => 'App\Modules\Shipping\Internal\RateTable'])
        ->and(findingsUnder(new BoundaryViolationRule, $files, []))->toBe([]);
});

it('says so when modules have no public surface at all', function (): void {
    $files = ['app/Modules/Billing/A.php' => '<?php namespace App\Modules\Billing; class A { public function b() { return new \App\Modules\Shipping\B(); } }'];

    $findings = findingsUnder(new BoundaryViolationRule, $files, ['boundaries' => ['modules' => 'App\Modules\{module}\*']]);

    expect($findings[0]->suggestion)->toStartWith('Modules share nothing but the shared kernel here.');
});

it('leaves the abstractions a role intends to the architecture', function (): void {
    $files = [
        'app/Domain/Ports/RateSource.php' => '<?php namespace App\Domain\Ports; interface RateSource { public function rates(): array; }',
        'app/Infrastructure/HttpRateSource.php' => '<?php namespace App\Infrastructure; class HttpRateSource implements \App\Domain\Ports\RateSource {
            public function __construct(private \Vendor\Sdk $sdk) {}
            public function rates(): array { return $this->sdk->rates(); }
            public function refresh(): void { $this->sdk->refresh(); }
        }',
        'app/Domain/Quote.php' => '<?php namespace App\Domain; class Quote { public function __construct(private Ports\RateSource $rates) {} }',
    ];

    expect(findingsAcross(new SingleUseAbstractionRule, $files))->not->toBe([])
        ->and(findingsAcross(new EmptyWrapperClassRule, $files))->not->toBe([])
        ->and(findingsUnder(new SingleUseAbstractionRule, $files, ['preset' => 'hexagonal']))->toBe([])
        ->and(findingsUnder(new EmptyWrapperClassRule, $files, ['preset' => 'hexagonal']))->toBe([])
        ->and(findingsUnder(new AbstractionInflationRule, $files, ['preset' => 'hexagonal']))->toBe([]);
});

it('describes the three policy rules and runs them only when something is declared', function (): void {
    $default = Profile::default();
    $declared = Profile::fromArray(['preset' => 'hexagonal']);
    $modular = Profile::fromArray(['preset' => 'modular']);

    foreach ([new LayerViolationRule, new ForbiddenCapabilityRule, new BoundaryViolationRule] as $rule) {
        expect($rule->category())->toBe(Category::Dependencies)
            ->and($rule->severity())->toBe(Severity::Medium)
            ->and($rule->explanation())->not->toBe('')
            ->and($rule->description())->not->toBe('')
            ->and($rule->appliesTo($default))->toBeFalse();
    }

    expect((new LayerViolationRule)->appliesTo($declared))->toBeTrue()
        ->and((new ForbiddenCapabilityRule)->appliesTo($declared))->toBeTrue()
        ->and((new BoundaryViolationRule)->appliesTo($declared))->toBeFalse()
        ->and((new BoundaryViolationRule)->appliesTo($modular))->toBeTrue()
        ->and(array_map(static fn (\Heyosseus\Sloppy\Rules\Architecture\LayerViolationRule|\Heyosseus\Sloppy\Rules\Architecture\ForbiddenCapabilityRule|BoundaryViolationRule $rule): string => $rule->name(), [new LayerViolationRule, new ForbiddenCapabilityRule, new BoundaryViolationRule]))
        ->toBe(['Layer Violation', 'Forbidden Capability', 'Boundary Violation']);
});

it('reports outbound HTTP from a form request as a form request', function (): void {
    $files = ['app/Http/Requests/StoreOrder.php' => '<?php namespace App\Http\Requests; class StoreOrder extends \Illuminate\Foundation\Http\FormRequest { public function rules() { \Illuminate\Support\Facades\Http::get("https://x.test"); return []; } }'];

    $findings = findingsUnder(new DirectExternalApiRule, $files, []);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toContain('directly from a form request');
});
