<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\DependencyGraph;
use Heyosseus\Sloppy\Architecture\RoleEdge;

const GRAPH_APP = [
    'app/Http/Controllers/OrderController.php' => '<?php namespace App\Http\Controllers;
        use App\Repositories\OrderRepository;
        use App\Services\OrderService;
        class OrderController {
            public function __construct(private OrderRepository $orders, private OrderService $service) {}
            public function show(): \App\Models\Order { return new \App\Models\Order; }
        }',
    'app/Http/Controllers/RefundController.php' => '<?php namespace App\Http\Controllers; class RefundController { public function __construct(private \App\Repositories\OrderRepository $orders) {} }',
    'app/Services/OrderService.php' => '<?php namespace App\Services; class OrderService { public function __construct(private \App\Repositories\OrderRepository $orders, private OtherService $other) {} }',
    'app/Services/OtherService.php' => '<?php namespace App\Services; class OtherService { public function now(): \Carbon\Carbon { return \Carbon\Carbon::now(); } }',
    'app/Repositories/OrderRepository.php' => '<?php namespace App\Repositories; class OrderRepository {}',
    'app/Models/Order.php' => '<?php namespace App\Models; class Order extends \Illuminate\Database\Eloquent\Model {}',
    'app/Support/Str.php' => '<?php namespace App\Support; class Str { public function a(): \App\Services\OrderService { return new \App\Services\OrderService; } }',
    'app/anonymous.php' => '<?php $x = new class { public function a(): \App\Services\OrderService { return new \App\Services\OrderService; } };',
];

it('counts the dependencies between roles, and marks the ones a policy forbids', function (): void {
    $graph = DependencyGraph::of(architectureSnapshotOf(GRAPH_APP, ['preset' => 'service-repository']));

    expect($graph->nodes)->toBe(['form-request' => 0, 'model' => 1, 'controller' => 2, 'middleware' => 0, 'repository-contract' => 0, 'repository' => 1, 'service' => 2])
        ->and(array_map(static fn (RoleEdge $edge): array => $edge->toArray(), $graph->edges))->toBe([
            ['from' => 'controller', 'to' => 'model', 'dependencies' => 1, 'forbidden' => 0, 'example' => null],
            ['from' => 'controller', 'to' => 'repository', 'dependencies' => 2, 'forbidden' => 2, 'example' => 'OrderController -> OrderRepository'],
            ['from' => 'controller', 'to' => 'service', 'dependencies' => 1, 'forbidden' => 0, 'example' => null],
            ['from' => 'service', 'to' => 'repository', 'dependencies' => 1, 'forbidden' => 0, 'example' => null],
        ]);
});

it('draws the graph in Mermaid, with forbidden edges in red', function (): void {
    $mermaid = DependencyGraph::of(architectureSnapshotOf(GRAPH_APP, ['preset' => 'service-repository']))->render('mermaid');

    expect($mermaid)->toStartWith("flowchart LR\n")
        ->and($mermaid)->toContain('    repository_contract["repository-contract (0)"]')
        ->and($mermaid)->toContain('    controller -->|2 (2 forbidden)| repository')
        ->and($mermaid)->toContain('    linkStyle 1 stroke:#d73a49,stroke-width:2px,color:#d73a49')
        ->and($mermaid)->toContain('    service -->|1| repository')
        ->and(substr_count($mermaid, 'linkStyle'))->toBe(1);
});

it('draws the graph in Graphviz and writes it as JSON', function (): void {
    $graph = DependencyGraph::of(architectureSnapshotOf(GRAPH_APP, ['preset' => 'service-repository']));
    $dot = $graph->render('dot');

    /** @var array{schema: int, roles: array<string, int>, edges: list<array<string, mixed>>} $json */
    $json = json_decode($graph->render('json'), true, flags: JSON_THROW_ON_ERROR);

    expect($dot)->toStartWith("digraph architecture {\n    rankdir=LR;")
        ->and($dot)->toContain('"form-request" [label="form-request\n0 classes"];')
        ->and($dot)->toContain('"controller" -> "repository" [label="2 (2 forbidden)", color="#d73a49", fontcolor="#d73a49"];')
        ->and($dot)->toContain('"controller" -> "service" [label="1"];')
        ->and($dot)->toEndWith("}\n")
        ->and($json['schema'])->toBe(1)
        ->and($json['roles']['controller'])->toBe(2)
        ->and($json['edges'])->toHaveCount(4);
});

it('refuses a format it cannot draw', function (): void {
    expect(static fn (): string => DependencyGraph::of(architectureSnapshotOf([]))->render('svg'))
        ->toThrow(InvalidArgumentException::class, 'sloppy architecture graph writes mermaid, dot, json, not svg.');
});

it('knows where each role lives and what its classes are called', function (): void {
    $snapshot = architectureSnapshotOf([
        ...GRAPH_APP,
        'app/Services/Billing/InvoiceService.php' => '<?php namespace App\Services\Billing; class InvoiceService {}',
    ]);

    expect($snapshot->namespacesOf('service'))->toBe(['App\Services' => 2, 'App\Repositories' => 1, 'App\Services\Billing' => 1])
        ->and($snapshot->directoryOf('service'))->toBe('app/Services')
        ->and($snapshot->suffixOf('service'))->toBe('Service')
        ->and($snapshot->suffixOf('controller'))->toBe('Controller')
        ->and($snapshot->suffixOf('model'))->toBeNull()
        ->and($snapshot->directoryOf('middleware'))->toBeNull()
        ->and($snapshot->roleOf('App\Support\Str'))->toBeNull()
        ->and($snapshot->unclassified())->toBe(['App\Support\Str'])
        ->and(architectureSnapshotOf(['a.php' => '<?php class Lonely {}'], ['roles' => ['lonely' => ['kind' => 'class']]])->namespacesOf('lonely'))->toBe(['' => 1]);
});
