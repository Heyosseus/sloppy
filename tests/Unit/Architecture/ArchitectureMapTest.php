<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Architecture\ArchitectureMap;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;

/**
 * The role of the first declaration in `$code`, with `$others` indexed
 * alongside it so `extends` can follow parents.
 *
 * @param  array<string, mixed>  $architecture
 * @param  array<string, string>  $others  Relative path => source.
 */
function roleOfFirst(string $code, array $architecture = [], array $others = [], string $path = 'app/Example.php'): ?string
{
    $parser = new Parser;
    $file = $parser->parse($path, "<?php\n\n".$code);
    $files = [$file];

    foreach ($others as $otherPath => $source) {
        $files[] = $parser->parse($otherPath, "<?php\n\n".$source);
    }

    $map = new ArchitectureMap(Profile::fromArray($architecture));

    return $map->matchNode($file->classLikes()[0], $path, ProjectIndex::build($files))->name();
}

it('gives a class the first role it matches, in profile order', function (): void {
    $architecture = ['preset' => 'none', 'roles' => [
        'action' => ['suffix' => 'Action'],
        'domain' => ['namespace' => 'App\Domain\*'],
    ]];

    expect(roleOfFirst('namespace App\Domain; class RefundAction {}', $architecture))->toBe('action')
        ->and(roleOfFirst('namespace App\Domain; class Refund {}', $architecture))->toBe('domain')
        ->and(roleOfFirst('namespace App\Support; class Clock {}', $architecture))->toBeNull();
});

it('names the roles a class lost, so a surprising answer can be explained', function (): void {
    $map = new ArchitectureMap(Profile::fromArray(['preset' => 'none', 'roles' => [
        'action' => ['suffix' => 'Action'],
        'domain' => ['namespace' => 'App\Domain\*'],
        'value' => ['kind' => 'enum'],
    ]]));
    $file = parsedFile('namespace App\Domain; class RefundAction {}');
    $match = $map->matchNode($file->classLikes()[0], $file->relativePath, ProjectIndex::build([$file]));

    expect($match->name())->toBe('action')
        ->and(array_map(static fn (Heyosseus\Sloppy\Architecture\Role $role): string => $role->name, $match->alsoMatched))->toBe(['domain']);
});

it('answers about a class it only holds a summary of, and the same way', function (): void {
    $file = parsedFile('namespace App\Http\Controllers; class OrderController {}');
    $index = ProjectIndex::build([$file]);
    $map = new ArchitectureMap;

    expect($map->matchClass('App\Http\Controllers\OrderController', $index)?->name())->toBe('controller')
        ->and($map->matchNode($file->classLikes()[0], $file->relativePath, $index)->name())->toBe('controller')
        ->and($map->matchClass('App\Missing', $index))->toBeNull();
});

it('matches globs on names and paths, where a backslash is a separator and never an escape', function (): void {
    $architecture = ['preset' => 'none', 'roles' => [
        'action' => ['namespace' => 'App\Domain\*\Actions\*'],
        'legacy' => ['path' => 'app/Legacy/*'],
    ]];

    expect(roleOfFirst('namespace App\Domain\Billing\Actions; class Refund {}', $architecture))->toBe('action')
        ->and(roleOfFirst('namespace App\Domain\Billing; class Refund {}', $architecture))->toBeNull()
        ->and(roleOfFirst('class Old {}', $architecture, path: 'app/Legacy/Old.php'))->toBe('legacy');
});

it('reads parents directly with parent, and through the project with extends', function (): void {
    $architecture = ['preset' => 'none', 'roles' => [
        'direct' => ['parent' => 'App\Base'],
        'inherited' => ['extends' => 'App\Base'],
    ]];
    $base = ['app/Base.php' => 'namespace App; class Base {}', 'app/Middle.php' => 'namespace App; class Middle extends Base {}'];

    expect(roleOfFirst('namespace App; class Child extends Base {}', $architecture, $base))->toBe('direct')
        ->and(roleOfFirst('namespace App; class Grandchild extends Middle {}', $architecture, $base))->toBe('inherited')
        ->and(roleOfFirst('namespace App; class Loner {}', $architecture, $base))->toBeNull();
});

it('survives an inheritance cycle the project declares by mistake', function (): void {
    $others = ['app/B.php' => 'namespace App; class B extends A {}'];

    expect(roleOfFirst('namespace App; class A extends B {}', ['preset' => 'none', 'roles' => [
        'base' => ['extends' => 'App\Base'],
    ]], $others))->toBeNull();
});

it('matches interfaces, traits, attributes and kinds', function (): void {
    $architecture = ['preset' => 'none', 'roles' => [
        'listener' => ['implements' => 'App\Contracts\*'],
        'action' => ['uses' => 'Lorisleiva\Actions\Concerns\AsAction'],
        'command' => ['attribute' => 'Symfony\Component\Console\Attribute\AsCommand'],
        'value' => ['kind' => ['enum', 'interface']],
    ]];

    expect(roleOfFirst('namespace App; class L implements Contracts\Listens {}', $architecture))->toBe('listener')
        ->and(roleOfFirst('namespace App; class A { use \Lorisleiva\Actions\Concerns\AsAction; }', $architecture))->toBe('action')
        ->and(roleOfFirst('namespace App; #[\Symfony\Component\Console\Attribute\AsCommand("x")] class C {}', $architecture))->toBe('command')
        ->and(roleOfFirst('namespace App; enum Status {}', $architecture))->toBe('value')
        ->and(roleOfFirst('namespace App; interface Shape {}', $architecture))->toBe('value')
        ->and(roleOfFirst('namespace App; trait T {}', $architecture))->toBeNull();
});

it('combines keys with and, alternatives with any, and negation with not', function (): void {
    $architecture = ['preset' => 'none', 'roles' => [
        'handler' => [
            'kind' => 'class',
            'any' => [['suffix' => 'Handler'], ['namespace' => 'App\Handlers\*']],
            'not' => ['suffix' => 'TestHandler'],
        ],
    ]];

    expect(roleOfFirst('namespace App; class PaymentHandler {}', $architecture))->toBe('handler')
        ->and(roleOfFirst('namespace App\Handlers; class Payment {}', $architecture))->toBe('handler')
        ->and(roleOfFirst('namespace App; class PaymentTestHandler {}', $architecture))->toBeNull()
        ->and(roleOfFirst('namespace App; interface PaymentHandler {}', $architecture))->toBeNull();
});

it('can give an anonymous class a role from what it extends', function (): void {
    $file = parsedFile('namespace App; $x = new class extends \Illuminate\Routing\Controller {};');
    $context = new AnalysisContext($file, ProjectIndex::build([$file]));

    expect($context->roleOf($file->classLikes()[0]))->toBe('controller')
        ->and($context->hasRole($file->classLikes()[0], 'service'))->toBeFalse();
});

it('describes what a role matches in words', function (): void {
    $role = Profile::fromArray(['preset' => 'none', 'roles' => [
        'handler' => ['kind' => 'class', 'any' => [['suffix' => 'Handler'], ['namespace' => ['App\H\*', 'App\G\*']]], 'not' => ['suffix' => 'X']],
    ]])->roles[0];

    expect($role->matcher->describe())
        ->toBe('kind class, and any of (suffix Handler; namespace App\H\* or App\G\*), and not (suffix X)');
});
