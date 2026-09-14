<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Tests\Support\TempTree;
use Heyosseus\Sloppy\Tests\Support\UnreadableDirectory;

afterEach(function (): void {
    UnreadableDirectory::unregister();
});

it('removes a nested tree', function (): void {
    $root = sys_get_temp_dir().'/sloppy-tree-'.bin2hex(random_bytes(6));
    mkdir($root.'/nested/deeper', 0o777, true);
    file_put_contents($root.'/nested/deeper/file.php', '<?php');

    TempTree::remove($root);

    expect(is_dir($root))->toBeFalse();
});

it('gives up on a directory it cannot open instead of warning', function (): void {
    UnreadableDirectory::register();

    // The state a teardown actually meets: something reports itself as a
    // directory, and opening it fails anyway. On CI that is git repacking
    // `.git/objects` out from under the walk; the warning it raises fails an
    // otherwise passing test, which is the one thing a teardown must never do.
    TempTree::remove(UnreadableDirectory::path('objects'));

    expect(true)->toBeTrue();
});
