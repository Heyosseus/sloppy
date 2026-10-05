<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Rules\Architecture\RoleShapeRule;
use Heyosseus\Sloppy\Tests\Support\RuleTester;

const ACTIONS_APP = [
    'app/Actions/RefundOrder.php' => '<?php namespace App\Actions;
        class RefundOrder {
            public function __construct(private int $limit) {}
            public function handle(int $id): void {}
            public function asController(int $id): void { $this->handle($id); }
            public function cancel(int $id): void {}
            public static function audit(): void {}
            private function helper(): void {}
            protected function hook(): void {}
        }',
    'app/Actions/BaseAction.php' => '<?php namespace App\Actions; abstract class BaseAction { public function anything(): void {} }',
    'app/Actions/Contract.php' => '<?php namespace App\Actions; interface Contract { public function anything(): void; }',
];

it('reports each public method an action may not have, and nothing for the hooks it may', function (): void {
    $findings = findingsUnder(new RoleShapeRule, ACTIONS_APP, ['preset' => 'laravel-actions']);

    expect(array_map(static fn (Finding $finding): string => $finding->message, $findings))->toBe([
        'RefundOrder::cancel() is public, but classes in the action role may have only these public methods: handle, execute, as*, get*, configure*, rules, authorize, prepareForValidation, withValidator, afterValidator, jsonResponse, htmlResponse.',
        'RefundOrder::audit() is public, but classes in the action role may have only these public methods: handle, execute, as*, get*, configure*, rules, authorize, prepareForValidation, withValidator, afterValidator, jsonResponse, htmlResponse.',
    ])
        ->and($findings[0]->ruleId)->toBe('SL308')
        ->and($findings[0]->location->line)->toBe(6)
        ->and($findings[0]->fingerprint)->toBe('RefundOrder::cancel')
        ->and($findings[0]->metrics)->toBe(['role' => 'action', 'shape' => 'public_method', 'method' => 'cancel'])
        ->and($findings[0]->suggestion)->toStartWith('Controllers, jobs and commands call actions;')
        ->and($findings[0]->suggestion)->toEndWith('give it a class of its own (sloppy.architecture.policies.action, preset laravel-actions).');
});

it('reports a class left open in a role that is final', function (): void {
    $architecture = ['roles' => ['handler' => ['suffix' => 'Handler']], 'policies' => ['handler' => ['final' => true, 'public_methods' => []]]];
    $files = [
        'app/OpenHandler.php' => '<?php namespace App; class OpenHandler { public function __invoke(): void {} }',
        'app/ClosedHandler.php' => '<?php namespace App; final class ClosedHandler { public function __invoke(): void {} public function run(): void {} }',
    ];

    $findings = findingsUnder(new RoleShapeRule, $files, $architecture);

    expect(array_map(static fn (Finding $finding): string => $finding->message, $findings))->toBe([
        'ClosedHandler::run() is public, but classes in the handler role may have only these public methods: none besides magic methods.',
        'OpenHandler is not final, but classes in the handler role must be.',
    ])
        ->and($findings[1]->fingerprint)->toBe('OpenHandler:final')
        ->and($findings[1]->metrics)->toBe(['role' => 'handler', 'shape' => 'final'])
        ->and($findings[1]->suggestion)->toBe('Declare it final. If it really must be extended, it may not belong in this role (sloppy.architecture.policies.handler, sloppy.php).');
});

it('stays silent with no shape declared, for anonymous classes and for roles without a shape', function (): void {
    $files = [
        ...ACTIONS_APP,
        'app/Actions/Factory.php' => '<?php namespace App\Actions; class MakesThings { public function make() { return new class { public function stray(): void {} }; } }',
    ];

    expect(findingsUnder(new RoleShapeRule, ACTIONS_APP, []))->toBe([])
        ->and(findingsUnder(new RoleShapeRule, ACTIONS_APP, ['preset' => 'laravel-actions', 'policies' => ['action' => ['may_not' => ['http']]]]))->toBe([])
        ->and(findingsUnder(new RoleShapeRule, ['app/Services/Billing.php' => '<?php namespace App\Services; class Billing { public function a() {} }'], ['preset' => 'laravel-actions']))->toBe([])
        ->and(count(findingsUnder(new RoleShapeRule, $files, ['preset' => 'laravel-actions'])))->toBe(3);
});

it('describes itself and runs only when a policy declares a shape', function (): void {
    $rule = new RoleShapeRule;

    expect($rule->name())->toBe('Role Shape')
        ->and($rule->category())->toBe(Category::Dependencies)
        ->and($rule->severity())->toBe(Severity::Low)
        ->and($rule->description())->not->toBe('')
        ->and($rule->explanation())->not->toBe('')
        ->and($rule->appliesTo(Profile::default()))->toBeFalse()
        ->and($rule->appliesTo(Profile::fromArray(['preset' => 'service-repository'])))->toBeFalse()
        ->and($rule->appliesTo(Profile::fromArray(['preset' => 'laravel-actions'])))->toBeTrue();
});

it('matches allowed public methods by glob, and reports only the ones no glob covers', function (): void {
    $architecture = [
        'roles' => ['query' => ['suffix' => 'Query']],
        'policies' => ['query' => ['public_methods' => ['get*', 'count', '*ById']]],
    ];
    $files = ['app/OrderQuery.php' => '<?php namespace App; class OrderQuery {
        public function get(): array { return []; }
        public function getPaid(): array { return []; }
        public function count(): int { return 0; }
        public function findById(int $id): ?object { return null; }
        public function countPaid(): int { return 0; }
        public function delete(): void {}
    }'];

    $findings = findingsUnder(new RoleShapeRule, $files, $architecture);

    expect(array_map(static fn (Finding $finding): string => $finding->fingerprint, $findings))->toBe(['OrderQuery::countPaid', 'OrderQuery::delete'])
        ->and($findings[0]->message)->toBe('OrderQuery::countPaid() is public, but classes in the query role may have only these public methods: get*, count, *ById.')
        ->and($findings[0]->location->line)->toBe(6)
        ->and($findings[0]->confidence)->toBe(85)
        ->and($findings[0]->severity)->toBe(Severity::Low)
        ->and($findings[0]->suggestion)->toBe('Make countPaid() private if only this class uses it; if it is a second use case, give it a class of its own (sloppy.architecture.policies.query, sloppy.php).');
});

it('always allows magic methods, however strict the list', function (): void {
    $architecture = [
        'roles' => ['handler' => ['suffix' => 'Handler']],
        'policies' => ['handler' => ['public_methods' => []]],
    ];
    $files = ['app/PayHandler.php' => '<?php namespace App; class PayHandler {
        public function __construct() {}
        public function __invoke(): void {}
        public function __toString(): string { return ""; }
        public static function __callStatic(string $name, array $arguments): mixed { return null; }
    }'];

    expect(findingsUnder(new RoleShapeRule, $files, $architecture))->toBe([]);
});

it('reports a public static method like any other', function (): void {
    $architecture = [
        'roles' => ['handler' => ['suffix' => 'Handler']],
        'policies' => ['handler' => ['public_methods' => ['handle']]],
    ];
    $files = ['app/PayHandler.php' => '<?php namespace App; class PayHandler { public function handle(): void {} public static function make(): self { return new self; } }'];

    $findings = findingsUnder(new RoleShapeRule, $files, $architecture);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->metrics)->toBe(['role' => 'handler', 'shape' => 'public_method', 'method' => 'make']);
});

it('judges only final when the policy declares no method list', function (): void {
    $architecture = [
        'roles' => ['handler' => ['suffix' => 'Handler']],
        'policies' => ['handler' => ['final' => true]],
    ];
    $files = ['app/PayHandler.php' => "<?php namespace App;\n\nclass PayHandler\n{\n    public function a(): void {}\n    public function b(): void {}\n}"];

    $findings = findingsUnder(new RoleShapeRule, $files, $architecture);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->message)->toBe('PayHandler is not final, but classes in the handler role must be.')
        ->and($findings[0]->location->line)->toBe(3)
        ->and($findings[0]->confidence)->toBe(90);
});

it('reports both shapes on one class, each with the advice in front', function (): void {
    $architecture = [
        'roles' => ['handler' => ['suffix' => 'Handler']],
        'policies' => ['handler' => [
            'final' => true,
            'public_methods' => ['handle'],
            'advice' => 'A handler handles one message.',
        ]],
    ];
    $files = ['app/PayHandler.php' => '<?php namespace App; class PayHandler { public function handle(): void {} public function retry(): void {} }'];

    $findings = findingsUnder(new RoleShapeRule, $files, $architecture);

    expect(array_map(static fn (Finding $finding): string => $finding->fingerprint, $findings))->toBe(['PayHandler::retry', 'PayHandler:final'])
        ->and($findings[1]->suggestion)->toBe('A handler handles one message. Declare it final. If it really must be extended, it may not belong in this role (sloppy.architecture.policies.handler, sloppy.php).')
        ->and($findings[0]->suggestion)->toStartWith('A handler handles one message. Make retry() private');
});

it('names the profile file as the origin when the policy came from it', function (): void {
    $profile = Profile::fromArray([
        'roles' => ['handler' => ['suffix' => 'Handler']],
        'policies' => ['handler' => ['final' => true]],
    ], Profile::FILE);

    $findings = RuleTester::runAcross(
        new RoleShapeRule,
        ['app/PayHandler.php' => '<?php namespace App; class PayHandler {}'],
        $profile,
    );

    expect($findings[0]->suggestion)->toEndWith('(sloppy.architecture.policies.handler, sloppy-architecture.php).');
});

it('leaves abstract classes, interfaces, traits and enums alone, even in a final role', function (): void {
    $architecture = [
        'roles' => ['handler' => ['suffix' => 'Handler']],
        'policies' => ['handler' => ['final' => true, 'public_methods' => []]],
    ];
    $files = [
        'app/BaseHandler.php' => '<?php namespace App; abstract class BaseHandler { public function shared(): void {} }',
        'app/ContractHandler.php' => '<?php namespace App; interface ContractHandler { public function run(): void; }',
        'app/TraitHandler.php' => '<?php namespace App; trait TraitHandler { public function run(): void {} }',
        'app/EnumHandler.php' => '<?php namespace App; enum EnumHandler { case A; public function run(): void {} }',
    ];

    expect(findingsUnder(new RoleShapeRule, $files, $architecture))->toBe([]);
});

it('takes a severity override from its options', function (): void {
    $architecture = [
        'roles' => ['handler' => ['suffix' => 'Handler']],
        'policies' => ['handler' => ['final' => true]],
    ];

    $findings = findingsUnder(new RoleShapeRule(['severity' => 'high']), ['app/PayHandler.php' => '<?php namespace App; class PayHandler {}'], $architecture);

    expect($findings[0]->severity)->toBe(Severity::High);
});

it('treats method names case-insensitively, as PHP does', function (): void {
    $architecture = [
        'roles' => ['handler' => ['suffix' => 'Handler']],
        'policies' => ['handler' => ['public_methods' => ['handle', 'as*']]],
    ];
    $files = ['app/PayHandler.php' => '<?php namespace App; class PayHandler { public function Handle(): void {} public function AsJob(): void {} public function other(): void {} }'];

    expect(array_map(static fn (Finding $finding): string => $finding->fingerprint, findingsUnder(new RoleShapeRule, $files, $architecture)))
        ->toBe(['PayHandler::other']);
});

it('includes public methods a class takes from a trait the index knows', function (): void {
    $files = [
        'app/Actions/RefundOrder.php' => '<?php namespace App\Actions;
            class RefundOrder {
                use Cancels;
                use Audits { audit as private; }
                public function handle(int $id): void {}
            }',
        'app/Actions/Cancels.php' => '<?php namespace App\Actions; trait Cancels { public function cancel(int $id): void {} public function handle(int $id): void {} private function helper(): void {} }',
        'app/Actions/Audits.php' => '<?php namespace App\Actions; trait Audits { public function audit(): void {} }',
    ];

    $findings = findingsUnder(new RoleShapeRule, $files, ['preset' => 'laravel-actions']);

    expect(RuleTester::fingerprints($findings))->toBe(['RefundOrder::cancel'])
        ->and($findings[0]->message)->toStartWith('RefundOrder::cancel() is public through trait Cancels')
        ->and($findings[0]->location->line)->toBe(3);
});
