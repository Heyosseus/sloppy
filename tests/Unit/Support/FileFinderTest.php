<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Support\FileFinder;

/**
 * @param  array<string, mixed>  $overrides
 */
function finderFor(string $root, array $overrides = []): FileFinder
{
    return new FileFinder(Configuration::fromArray([
        'paths' => ['app'],
        'exclude' => ['vendor', 'storage'],
        ...$overrides,
    ], $root));
}

it('finds php files recursively and in a stable order', function (): void {
    $root = tempProject([
        'app/Zebra.php' => '<?php',
        'app/Alpha.php' => '<?php',
        'app/Nested/Deep/Thing.php' => '<?php',
        'app/notes.txt' => 'ignored',
    ]);

    $found = finderFor($root)->find();

    expect(array_map(static fn (string $p): string => str_replace($root.'/', '', $p), $found))
        ->toBe(['app/Alpha.php', 'app/Nested/Deep/Thing.php', 'app/Zebra.php']);

    removeTree($root);
});

it('skips excluded directories', function (): void {
    $root = tempProject([
        'app/Keep.php' => '<?php',
        'app/vendor/Skip.php' => '<?php',
        'storage/Skip.php' => '<?php',
    ]);

    $found = finderFor($root, ['paths' => ['app', 'storage']])->find();

    expect($found)->toHaveCount(1)
        ->and($found[0])->toEndWith('app/Keep.php');

    removeTree($root);
});

it('excludes a matching directory at any depth', function (): void {
    $finder = finderFor('/project');

    expect($finder->isExcluded('vendor/autoload.php'))->toBeTrue()
        ->and($finder->isExcluded('app/vendor/Thing.php'))->toBeTrue()
        ->and($finder->isExcluded('app/Vendors.php'))->toBeFalse()
        ->and($finder->isExcluded('app/vendored/Thing.php'))->toBeFalse();
});

it('supports glob exclusions', function (): void {
    $root = tempProject([
        'app/Keep.php' => '<?php',
        'app/views/home.blade.php' => '<?php',
    ]);

    $finder = finderFor($root, ['exclude' => ['*.blade.php']]);

    expect($finder->find())->toHaveCount(1)
        ->and($finder->isExcluded('app/views/home.blade.php'))->toBeTrue()
        ->and($finder->isExcluded('app/Keep.php'))->toBeFalse();

    removeTree($root);
});

it('accepts individual files as paths', function (): void {
    $root = tempProject([
        'app/One.php' => '<?php',
        'app/Two.php' => '<?php',
    ]);

    expect(finderFor($root, ['paths' => ['app/One.php']])->find())->toHaveCount(1);

    removeTree($root);
});

it('ignores paths that do not exist', function (): void {
    $root = tempProject(['app/One.php' => '<?php']);

    expect(finderFor($root, ['paths' => ['nope', 'app']])->find())->toHaveCount(1);

    removeTree($root);
});

it('ignores a non-php file listed directly', function (): void {
    $root = tempProject(['app/notes.txt' => 'x']);

    expect(finderFor($root, ['paths' => ['app/notes.txt']])->find())->toBe([]);

    removeTree($root);
});

it('reports project-relative paths with forward slashes', function (): void {
    $finder = finderFor('/project');

    expect($finder->relative('/project/app/Order.php'))->toBe('app/Order.php')
        ->and($finder->relative('/elsewhere/app/Order.php'))->toBe('elsewhere/app/Order.php');
});

it('resolves relative and absolute paths', function (): void {
    $finder = finderFor('/project');

    expect($finder->absolute('app'))->toBe('/project/app')
        ->and($finder->absolute('/tmp/other'))->toBe('/tmp/other')
        ->and($finder->absolute('C:\\tmp\\other'))->toBe('C:/tmp/other');
});

it('ignores blank exclusion entries', function (): void {
    expect(finderFor('/project', ['exclude' => ['', '/', 'vendor']])->isExcluded('app/A.php'))->toBeFalse();
});
