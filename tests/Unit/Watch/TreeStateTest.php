<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Watch\TreeState;

it('reports nothing changed between identical states', function (): void {
    $state = new TreeState(['app/A.php' => '100:20', 'app/B.php' => '100:30']);

    expect($state->changesSince(new TreeState(['app/A.php' => '100:20', 'app/B.php' => '100:30'])))->toBe([]);
});

it('reports a file whose stamp moved', function (): void {
    $before = new TreeState(['app/A.php' => '100:20', 'app/B.php' => '100:30']);
    $after = new TreeState(['app/A.php' => '100:20', 'app/B.php' => '101:34']);

    expect($after->changesSince($before))->toBe(['app/B.php']);
});

it('reports a file that appeared', function (): void {
    $before = new TreeState(['app/A.php' => '100:20']);
    $after = new TreeState(['app/A.php' => '100:20', 'app/New.php' => '100:12']);

    expect($after->changesSince($before))->toBe(['app/New.php']);
});

it('reports a file that disappeared', function (): void {
    $before = new TreeState(['app/A.php' => '100:20', 'app/Gone.php' => '100:12']);
    $after = new TreeState(['app/A.php' => '100:20']);

    expect($after->changesSince($before))->toBe(['app/Gone.php']);
});

it('sorts the changes, so two ticks over the same edit read the same', function (): void {
    $before = new TreeState([]);
    $after = new TreeState(['app/Z.php' => '1:1', 'app/A.php' => '1:1', 'app/M.php' => '1:1']);

    expect($after->changesSince($before))->toBe(['app/A.php', 'app/M.php', 'app/Z.php']);
});

it('stamps a real tree by modification time and size', function (): void {
    $root = tempProject(['app/A.php' => '<?php class A {}']);
    $map = ['app/A.php' => $root.'/app/A.php'];

    $before = TreeState::of($map);

    // A different length is what an edit almost always changes, and it is
    // visible even when the write lands inside the same whole second.
    file_put_contents($root.'/app/A.php', '<?php class A { public function b(): void {} }');

    expect(TreeState::of($map)->changesSince($before))->toBe(['app/A.php']);

    removeTree($root);
});

it('stamps a file that is not there as missing rather than failing', function (): void {
    $state = TreeState::of(['app/Gone.php' => '/definitely/not/here.php']);

    expect($state->stamps)->toBe(['app/Gone.php' => '']);
});
