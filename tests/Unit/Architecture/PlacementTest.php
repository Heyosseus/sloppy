<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\Placement;

const PLACEMENT_APP = [
    'app/Actions/RefundOrderAction.php' => '<?php namespace App\Actions; class RefundOrderAction {}',
    'app/Actions/CancelOrderAction.php' => '<?php namespace App\Actions; class CancelOrderAction {}',
    'app/Http/Controllers/OrderController.php' => '<?php namespace App\Http\Controllers; class OrderController {}',
    'app/Services/Billing.php' => '<?php namespace App\Services; class Billing {}',
];

it('places a described class in the role that fits, with where it goes and what it may do', function (): void {
    $placement = new Placement(architectureSnapshotOf(PLACEMENT_APP, ['preset' => 'laravel-actions']));

    $answer = $placement->answer('an Action that refunds orders', 'IssueRefund');

    expect($answer['query'])->toBe('an Action that refunds orders')
        ->and($answer['alternatives'])->toBe(['service'])
        ->and($answer['roles'])->toBe(['form-request', 'model', 'controller', 'middleware', 'action', 'service'])
        ->and($answer['role'])->toMatchArray([
            'name' => 'action',
            'namespace' => 'App\Actions',
            'directory' => 'app/Actions',
            'suffix' => 'Action',
            'class' => 'App\Actions\IssueRefundAction',
            'file' => 'app/Actions/IssueRefundAction.php',
            'examples' => ['App\Actions\CancelOrderAction', 'App\Actions\RefundOrderAction'],
            'origin' => 'preset laravel-actions',
        ])
        ->and($answer['role']['instructions'] ?? [])->toContain('Never depend on: controller.');

    expect($placement->text($answer))->toBe(implode("\n", [
        '"an Action that refunds orders" belongs in the action role: One use case, behind one public method.',
        '- Class: App\Actions\IssueRefundAction',
        '- File: app/Actions/IssueRefundAction.php',
        '- Namespace: App\Actions',
        '- Directory: app/Actions',
        '- Name ends in: Action',
        '- Matched by: kind class, and any of (suffix Action; namespace *\Actions\*)',
        '- Controllers, jobs and commands call actions; an action never calls back into a controller, and does one thing behind handle().',
        '- Never depend on: controller.',
        '- Public methods: handle, execute, as*, get*, configure*, rules, authorize, prepareForValidation, withValidator, afterValidator, jsonResponse, htmlResponse.',
        '- For example: App\Actions\CancelOrderAction, App\Actions\RefundOrderAction',
        'Also close: service.',
    ])."\n");
});

it('keeps a name that already ends in the suffix, and knows a role nobody plays yet', function (): void {
    $placement = new Placement(architectureSnapshotOf(PLACEMENT_APP, ['preset' => 'laravel-actions']));

    $action = $placement->answer('action', 'RefundOrderAction');
    $request = $placement->answer('form request validating an order');

    expect($action['role']['class'] ?? null)->toBe('App\Actions\RefundOrderAction')
        ->and($request['role']['name'] ?? null)->toBe('form-request')
        ->and($request['role'])->toHaveKey('namespace', null)
        ->and($request['role'])->toHaveKey('class', null)
        ->and($placement->text($request))->toContain('- No class plays this role yet.')
        ->and($placement->text($request))->not->toContain('- Class:');
});

it('says so when nothing fits, and names the roles there are', function (): void {
    $placement = new Placement(architectureSnapshotOf(PLACEMENT_APP, ['preset' => 'laravel-actions']));
    $answer = $placement->answer('the a of');

    expect($answer['role'])->toBeNull()
        ->and($answer['alternatives'])->toBe([])
        ->and($placement->text($answer))->toBe("No role in this project's architecture matches \"the a of\". Roles: form-request, model, controller, middleware, action, service.\nName one of them, or declare a role for this kind of class in sloppy.architecture.roles.\n");
});

it('suggests a bare class name when the role has no namespace yet', function (): void {
    $placement = new Placement(architectureSnapshotOf([], ['preset' => 'none', 'roles' => ['handler' => ['suffix' => 'Handler']]]));

    $answer = $placement->answer('a handler for payments', 'PaymentHandler');

    expect($answer['role']['class'] ?? null)->toBe('PaymentHandler')
        ->and($answer['role'])->toHaveKey('file', null)
        ->and($answer['role'])->toHaveKey('description', null)
        ->and($placement->text($answer))->toStartWith('"a handler for payments" belongs in the handler role: no description');
});
