<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Rules\Architecture\RoleShapeRule;

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
