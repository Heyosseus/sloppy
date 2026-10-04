<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Architecture\ArchitectureMap;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use PhpParser\Node\Stmt\ClassLike;

/**
 * What the rules concluded before roles existed, one predicate per rule, with
 * the preset's precedence where the predicates overlapped. The preset must
 * agree with this on every declaration below.
 */
function roleBeforeProfiles(ClassLike $class): ?string
{
    return match (true) {
        NodeHelper::isFormRequest($class) => 'form-request',
        NodeHelper::isEloquentModel($class) => 'model',
        NodeHelper::isController($class) => 'controller',
        NodeHelper::isMiddleware($class) => 'middleware',
        NodeHelper::isServiceClass($class) => 'service',
        default => null,
    };
}

/**
 * @return iterable<string, array{string}>
 */
function laravelDeclarations(): iterable
{
    $namespaces = ['App', 'App\Http\Controllers', 'App\Http\Controllers\Api', 'App\Http\Middleware', 'App\Services', 'App\Actions', 'App\Domain\Billing', 'App\Application', 'App\Models', 'Modules\Shop\Http\Controllers'];
    $names = ['Order', 'OrderController', 'OrderTestController', 'Controller', 'OrderService', 'RefundAction', 'SyncManager', 'EventHandler', 'OrderRepository', 'PlaceOrderUseCase', 'Interactor', 'StoreOrderRequest'];
    $parents = ['', 'Controller', 'BaseController', '\Illuminate\Routing\Controller', '\Illuminate\Database\Eloquent\Model', 'Model', '\Illuminate\Foundation\Auth\User', '\Illuminate\Database\Eloquent\Relations\Pivot', '\Illuminate\Foundation\Http\FormRequest', 'BaseFormRequest'];

    foreach ($namespaces as $namespace) {
        foreach ($names as $name) {
            foreach ($parents as $parent) {
                $extends = $parent === '' ? '' : ' extends '.$parent;

                yield sprintf('%s\%s%s', $namespace, $name, $extends) => [sprintf('namespace %s; class %s%s {}', $namespace, $name, $extends)];
            }

            foreach (['interface', 'trait', 'enum'] as $kind) {
                yield sprintf('%s %s\%s', $kind, $namespace, $name) => [sprintf('namespace %s; %s %s {}', $namespace, $kind, $name)];
            }
        }
    }
}

it('gives every declaration the role the rules assumed before profiles existed', function (): void {
    $map = new ArchitectureMap;
    $disagreements = [];

    foreach (laravelDeclarations() as $label => [$code]) {
        $file = parsedFile($code);
        $class = $file->classLikes()[0];
        $expected = roleBeforeProfiles($class);
        $actual = $map->matchNode($class, $file->relativePath, ProjectIndex::build([$file]))->name();

        if ($expected !== $actual) {
            $disagreements[] = sprintf('%s: expected %s, got %s', $label, $expected ?? 'none', $actual ?? 'none');
        }
    }

    expect($disagreements)->toBe([]);
});

it('keeps the anonymous-class answers too', function (string $code, ?string $role): void {
    $file = parsedFile($code);
    $class = $file->classLikes()[0];

    expect((new ArchitectureMap)->matchNode($class, $file->relativePath, ProjectIndex::build([$file]))->name())
        ->toBe($role)
        ->and(roleBeforeProfiles($class))->toBe($role);
})->with([
    'extending a controller' => ['$x = new class extends Controller {};', 'controller'],
    'extending a model' => ['$x = new class extends \Illuminate\Database\Eloquent\Model {};', 'model'],
    'extending nothing' => ['$x = new class {};', null],
]);
