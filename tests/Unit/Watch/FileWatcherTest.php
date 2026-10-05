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

it('polls every tick while the tree is changing, and backs off while it holds still', function (): void {
    $now = 0.0;
    $watcher = new FileWatcher(2.0, static function () use (&$now): float {
        return $now;
    });

    $watcher->poll(state('1:1'));

    // The first few quiet polls stay eager: a pause between two saves is not
    // a reason to stop looking.
    foreach (range(1, 4) as $tick) {
        expect($watcher->due())->toBeTrue();
        $watcher->poll(state('1:1'));
    }

    // Then the gaps double, up to the ceiling.
    $delays = [];

    foreach (range(1, 6) as $tick) {
        $delays[] = $watcher->delay();
        $now += $watcher->delay();
        expect($watcher->due())->toBeTrue();
        $watcher->poll(state('1:1'));
    }

    expect($delays)->toBe([0.25, 0.5, 1.0, 2.0, 2.0, 2.0])
        ->and($watcher->due())->toBeFalse();

    // A change brings it straight back to every tick.
    $now += $watcher->delay();
    $watcher->poll(state('2:2'));

    expect($watcher->due())->toBeTrue()
        ->and($watcher->poll(state('2:2')))->toBe(['app/File0.php'])
        ->and($watcher->due())->toBeTrue();
});

it('spaces polls out by how long a walk of the tree takes', function (): void {
    $now = 0.0;
    $watcher = new FileWatcher(5.0, static function () use (&$now): float {
        return $now;
    });

    $watcher->poll(state('1:1'), 0.1);

    // A tenth of a second to walk the tree is at most one walk a second.
    expect($watcher->due())->toBeFalse()
        ->and($watcher->delay())->toBe(1.0);

    // Unless a change is settling: then the next tick looks again.
    $now += 1.0;
    $watcher->poll(state('2:2'), 0.1);

    expect($watcher->due())->toBeTrue();
});
