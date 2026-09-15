<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Watch\FileWatcher;
use Heyosseus\Sloppy\Watch\TreeState;

function state(string ...$stamps): TreeState
{
    $map = [];

    foreach ($stamps as $index => $stamp) {
        $map['app/File'.$index.'.php'] = $stamp;
    }

    return new TreeState($map);
}

it('reports nothing on the first poll', function (): void {
    // The first poll only learns what is there; the runner has already
    // analysed once by the time it happens.
    expect((new FileWatcher)->poll(state('1:1', '1:1')))->toBe([]);
});

it('reports nothing while the tree is unchanged', function (): void {
    $watcher = new FileWatcher;
    $watcher->poll(state('1:1'));

    expect($watcher->poll(state('1:1')))->toBe([]);
});

it('holds a change back until the tree settles', function (): void {
    $watcher = new FileWatcher;
    $watcher->poll(state('1:1'));

    // An editor writing a file in two syscalls, or a coding agent rewriting
    // twenty files, must not trigger twenty analyses.
    expect($watcher->poll(state('2:9')))->toBe([])
        ->and($watcher->poll(state('2:9')))->toBe(['app/File0.php']);
});

it('keeps collecting while writes are still arriving', function (): void {
    $watcher = new FileWatcher;
    $watcher->poll(state('1:1', '1:1'));

    expect($watcher->poll(state('2:9', '1:1')))->toBe([])
        ->and($watcher->poll(state('2:9', '3:7')))->toBe([])
        ->and($watcher->poll(state('2:9', '3:7')))->toBe(['app/File0.php', 'app/File1.php']);
});

it('falls quiet once it has reported a settled change', function (): void {
    $watcher = new FileWatcher;
    $watcher->poll(state('1:1'));
    $watcher->poll(state('2:9'));
    $watcher->poll(state('2:9'));

    expect($watcher->poll(state('2:9')))->toBe([]);
});

it('reports a file twice when it is written twice', function (): void {
    $watcher = new FileWatcher;
    $watcher->poll(state('1:1'));
    $watcher->poll(state('2:9'));

    expect($watcher->poll(state('2:9')))->toBe(['app/File0.php'])
        ->and($watcher->poll(state('3:4')))->toBe([])
        ->and($watcher->poll(state('3:4')))->toBe(['app/File0.php']);
});
