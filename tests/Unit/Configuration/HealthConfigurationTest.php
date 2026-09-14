<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Configuration\HealthConfiguration;

it('defaults to a cache beside the project, refreshed every fifteen minutes', function (): void {
    $health = HealthConfiguration::fromArray([]);

    expect($health->cache)->toBe('.sloppy-health.json')
        ->and($health->ttl)->toBe(900)
        ->and($health->top)->toBe(5);
});

it('reads what the project configured', function (): void {
    $health = HealthConfiguration::fromArray([
        'cache' => 'storage/sloppy.json',
        'ttl' => 60,
        'top' => 3,
    ]);

    expect($health->cache)->toBe('storage/sloppy.json')
        ->and($health->ttl)->toBe(60)
        ->and($health->top)->toBe(3);
});

it('refuses a negative ttl and a top of zero', function (): void {
    $health = HealthConfiguration::fromArray(['ttl' => -1, 'top' => 0]);

    expect($health->ttl)->toBe(0)
        ->and($health->top)->toBe(1);
});

it('ignores values of the wrong type', function (): void {
    $health = HealthConfiguration::fromArray(['cache' => ['an', 'array'], 'ttl' => 'soon', 'top' => '3']);

    expect($health->cache)->toBe('.sloppy-health.json')
        ->and($health->ttl)->toBe(900)
        ->and($health->top)->toBe(5);
});

it('is reachable from the configuration, and resolves paths against the project', function (): void {
    $config = Configuration::fromArray(['health' => ['ttl' => 42]], '/srv/app');

    expect($config->health()->ttl)->toBe(42)
        ->and($config->absolutePath('build/x.json'))->toBe('/srv/app/build/x.json')
        ->and($config->absolutePath('/tmp/x.json'))->toBe('/tmp/x.json')
        ->and($config->absolutePath('C:/tmp/x.json'))->toBe('C:/tmp/x.json')
        ->and($config->absolutePath('C:\\tmp\\x.json'))->toBe('C:\\tmp\\x.json');
});
