<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Mcp\ProjectResolver;
use Heyosseus\Sloppy\Mcp\Tools\ArchitecturePromptTool;
use Heyosseus\Sloppy\Mcp\Tools\ArchitectureTool;
use Heyosseus\Sloppy\Mcp\Tools\PlaceTool;

/**
 * A project with a declared architecture an agent might ask about.
 */
function architectureToolProject(): string
{
    return tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"app/"}}}',
        'sloppy.php' => "<?php\n\nreturn ['paths' => ['app'], 'architecture' => ['preset' => 'laravel-actions']];\n",
        'app/Actions/RefundOrderAction.php' => '<?php namespace App\Actions; class RefundOrderAction {}',
        'app/Actions/CancelOrderAction.php' => '<?php namespace App\Actions; class CancelOrderAction {}',
        'app/Http/Controllers/OrderController.php' => '<?php namespace App\Http\Controllers; class OrderController { public function __construct(private \App\Actions\RefundOrderAction $refund) {} }',
        'app/Support/Clock.php' => '<?php namespace App\Support; class Clock {}',
        'app/Support/Other/Clock.php' => '<?php namespace App\Support\Other; class Clock {}',
    ]);
}

it('describes the architecture, one class, or the graph', function (): void {
    $root = architectureToolProject();
    $tool = new ArchitectureTool(new ProjectResolver($root));

    /** @var array{class: string, role: string} $explained */
    $explained = json_decode($tool->call(['class' => 'OrderController', 'format' => 'json']), true, flags: JSON_THROW_ON_ERROR);

    expect($tool->name())->toBe('sloppy_architecture')
        ->and($tool->description())->toContain('role each class plays')
        ->and($tool->inputSchema()['properties'])->toHaveKeys(['class', 'graph', 'project', 'format'])
        ->and($tool->call([]))->toContain('Architecture: preset laravel-actions')
        ->and($tool->call(['format' => 'json']))->toContain('"preset": "laravel-actions"')
        ->and($tool->call(['class' => 'RefundOrderAction']))->toContain('Role:     action, from preset laravel-actions')
        ->and($explained)->toMatchArray(['class' => 'App\Http\Controllers\OrderController', 'role' => 'controller'])
        ->and($tool->call(['graph' => true]))->toContain('controller -->|1| action')
        ->and($tool->call(['graph' => true, 'format' => 'json']))->toContain('"edges"')
        ->and(static fn (): string => $tool->call(['class' => 'Nothing']))->toThrow(RuntimeException::class, 'No class named Nothing in the analysed paths.')
        ->and(static fn (): string => $tool->call(['class' => 'Clock']))->toThrow(RuntimeException::class, 'Clock is ambiguous. Name one of: App\Support\Clock, App\Support\Other\Clock.');

    removeTree($root);
});

it('says where a new class belongs', function (): void {
    $root = architectureToolProject();
    $tool = new PlaceTool(new ProjectResolver($root));

    /** @var array{role: array{name: string, class: string}} $json */
    $json = json_decode($tool->call(['description' => 'an action that ships an order', 'name' => 'ShipOrder', 'format' => 'json']), true, flags: JSON_THROW_ON_ERROR);

    expect($tool->name())->toBe('sloppy_place')
        ->and($tool->description())->toContain('before you create it')
        ->and($tool->inputSchema()['required'])->toBe(['description'])
        ->and($tool->call(['description' => 'an action that ships an order', 'name' => 'ShipOrder']))->toContain('- Class: App\Actions\ShipOrderAction')
        ->and($json['role'])->toMatchArray(['name' => 'action', 'class' => 'App\Actions\ShipOrderAction'])
        ->and(static fn (): string => $tool->call([]))->toThrow(RuntimeException::class, 'Say what the class does');

    removeTree($root);
});

it('hands an agent what it needs to write a profile', function (): void {
    $root = architectureToolProject();
    $tool = new ArchitecturePromptTool(new ProjectResolver($root));

    expect($tool->name())->toBe('sloppy_architecture_prompt')
        ->and($tool->description())->toContain('sloppy-architecture.php')
        ->and($tool->inputSchema()['properties'])->toHaveKeys(['project', 'format'])
        ->and($tool->call([]))->toStartWith('# Describe this project\'s architecture for Sloppy')
        ->and(json_decode($tool->call(['format' => 'json']), true, flags: JSON_THROW_ON_ERROR))->toHaveKeys(['schema', 'file', 'rules', 'format', 'facts', 'draft']);

    removeTree($root);
});
