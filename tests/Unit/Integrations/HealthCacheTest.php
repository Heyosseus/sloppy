<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Integrations\HealthCache;
use Heyosseus\Sloppy\Integrations\HealthSnapshot;

function snapshotAt(int $time, int $findings = 1): HealthSnapshot
{
    return HealthSnapshot::from(
        analysisResult($findings === 0 ? [] : [finding()]),
        generatedAt: $time,
    );
}

it('answers with nothing when there is no file', function (): void {
    $root = tempProject();

    expect((new HealthCache($root.'/missing.json'))->read())->toBeNull();

    removeTree($root);
});

it('round-trips a snapshot through disk', function (): void {
    $root = tempProject();
    $cache = new HealthCache($root.'/health.json');
    $snapshot = snapshotAt(1_700_000_000);

    expect($cache->write($snapshot))->toBeTrue();

    $read = $cache->read(1_700_000_100);

    expect($read)->toBeInstanceOf(HealthSnapshot::class)
        ->and($read?->generatedAt)->toBe(1_700_000_000)
        ->and($read?->findings)->toBe(1);

    removeTree($root);
});

it('treats a snapshot older than the ttl as absent', function (): void {
    $root = tempProject();
    $cache = new HealthCache($root.'/health.json', ttl: 60);
    $cache->write(snapshotAt(1_700_000_000));

    expect($cache->read(1_700_000_030))->toBeInstanceOf(HealthSnapshot::class)
        ->and($cache->read(1_700_000_100))->toBeNull();

    removeTree($root);
});

it('treats an unreadable or malformed file as absent', function (): void {
    $root = tempProject(['health.json' => 'not json at all']);

    expect((new HealthCache($root.'/health.json'))->read())->toBeNull();

    removeTree($root);
});

it('reports that it could not write into a directory that is not there', function (): void {
    $root = tempProject();

    expect((new HealthCache($root.'/nope/health.json'))->write(snapshotAt(time())))->toBeFalse();

    removeTree($root);
});

it('refuses to write a snapshot that cannot be encoded', function (): void {
    $root = tempProject();

    // A finding quoting a byte sequence that is not valid UTF-8 -- which comes
    // out of a file saved in a legacy encoding -- cannot be JSON. Saying so is
    // the only honest answer; writing half a file is not.
    $snapshot = HealthSnapshot::fromArray(['top' => [['rule' => "SL101\xB1\x31"]]]);

    expect((new HealthCache($root.'/health.json'))->write($snapshot))->toBeFalse();

    removeTree($root);
});

it('remembers a fresh snapshot and computes a missing one once', function (): void {
    $root = tempProject();
    $cache = new HealthCache($root.'/health.json', ttl: 900);
    $computed = 0;

    $fresh = function () use (&$computed): HealthSnapshot {
        $computed++;

        return snapshotAt(1_700_000_000);
    };

    $first = $cache->remember($fresh, 1_700_000_000);
    $second = $cache->remember($fresh, 1_700_000_010);

    expect($computed)->toBe(1)
        ->and($first->generatedAt)->toBe($second->generatedAt)
        ->and(is_file($root.'/health.json'))->toBeTrue();

    removeTree($root);
});

it('forgets a snapshot on request, and shrugs when there is none', function (): void {
    $root = tempProject();
    $cache = new HealthCache($root.'/health.json');
    $cache->write(snapshotAt(time()));

    $cache->forget();
    $cache->forget();

    expect(is_file($root.'/health.json'))->toBeFalse();

    removeTree($root);
});
