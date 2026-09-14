<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Integrations\HealthCache;
use Heyosseus\Sloppy\Integrations\HealthReporter;
use Heyosseus\Sloppy\Integrations\HealthSnapshot;
use Heyosseus\Sloppy\Sloppy;

/**
 * A project with one god method in it, so the snapshot has something to say.
 *
 * @param  array<string, mixed>  $config
 * @return array{0: Sloppy, 1: string}
 */
function reportedProject(array $config = []): array
{
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Bad.php' => godMethodSource(),
    ]);

    return [new Sloppy(Configuration::fromArray([...['paths' => ['src']], ...$config], $root)), $root];
}

it('analyses the project and caches what it found', function (): void {
    [$sloppy, $root] = reportedProject();

    $snapshot = (new HealthReporter($sloppy))->current();

    expect($snapshot->findings)->toBeGreaterThan(0)
        ->and($snapshot->score)->toBeLessThan(100)
        ->and(is_file($root.'/.sloppy-health.json'))->toBeTrue();

    removeTree($root);
});

it('reads the cached snapshot rather than analysing again', function (): void {
    [$sloppy, $root] = reportedProject();

    $cached = HealthSnapshot::fromArray([
        'score' => 42,
        'findings' => 7,
        'generated_at' => time(),
    ]);

    (new HealthCache($root.'/.sloppy-health.json'))->write($cached);

    expect((new HealthReporter($sloppy))->current()->score)->toBe(42);

    removeTree($root);
});

it('ignores the cache and refreshes it when asked for something fresh', function (): void {
    [$sloppy, $root] = reportedProject();
    $cache = new HealthCache($root.'/.sloppy-health.json');
    $cache->write(HealthSnapshot::fromArray(['score' => 42, 'findings' => 7, 'generated_at' => time()]));

    $snapshot = (new HealthReporter($sloppy))->current(fresh: true);

    expect($snapshot->score)->not->toBe(42)
        ->and($cache->read()?->score)->toBe($snapshot->score);

    removeTree($root);
});

it('takes its cache path and ttl from the configuration', function (): void {
    [$sloppy, $root] = reportedProject(['health' => ['cache' => 'build/health.json', 'ttl' => 30]]);

    $cache = (new HealthReporter($sloppy))->cache();

    expect($cache->path)->toBe($root.'/build/health.json')
        ->and($cache->ttl)->toBe(30);

    removeTree($root);
});

it('keeps the number of ranked findings it was constructed with', function (): void {
    [$sloppy, $root] = reportedProject(['health' => ['top' => 4]]);

    expect((new HealthReporter($sloppy))->snapshot()->top)->toHaveCount(1)
        ->and((new HealthReporter($sloppy, 0))->snapshot()->top)->toBe([]);

    removeTree($root);
});
