<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\RuleRegistry;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\SloppyServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;

it('merges its configuration into the application', function (): void {
    $config = app(Repository::class);

    expect($config->get('sloppy.enabled'))->toBeTrue()
        ->and($config->get('sloppy.paths'))->toBe(['app'])
        ->and($config->get('sloppy.fail_on'))->toBe('high')
        ->and($config->get('sloppy.baseline'))->toBe('.sloppy-baseline.json');
});

it('publishes its configuration file under a tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(SloppyServiceProvider::class, 'sloppy-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toEndWith('sloppy.php');
});

it('binds a configuration rooted at the application path', function (): void {
    $configuration = app(Configuration::class);

    expect($configuration)->toBeInstanceOf(Configuration::class)
        ->and($configuration->basePath)->toBe(str_replace('\\', '/', rtrim(app()->basePath(), '/\\')))
        ->and($configuration->enabled())->toBeTrue();
});

it('binds the entry point with every rule wired up', function (): void {
    // Testbench's fake application skeleton has no `require` section in its
    // composer.json to detect, even though it is genuinely a Laravel app --
    // pin it explicitly rather than let that testing artefact skip rules.
    app(Repository::class)->set('sloppy.framework', 'laravel');

    $sloppy = app(Sloppy::class);

    expect($sloppy)->toBeInstanceOf(Sloppy::class)
        ->and($sloppy->rules()->count())->toBe(23)
        ->and($sloppy->rules()->ids())->toBe(RuleRegistry::withDefaults()->ids());
});

it('resolves the same instances on repeat lookups', function (): void {
    expect(app(Sloppy::class))->toBe(app(Sloppy::class))
        ->and(app(Configuration::class))->toBe(app(Configuration::class));
});

it('registers all three commands', function (): void {
    $commands = array_keys(app(Illuminate\Contracts\Console\Kernel::class)->all());

    expect($commands)->toContain('sloppy')
        ->toContain('sloppy:diff')
        ->toContain('sloppy:baseline');
});

it('exposes a working analyzer, finder and score calculator', function (): void {
    $sloppy = app(Sloppy::class);

    expect($sloppy->analyzer())->toBeInstanceOf(Heyosseus\Sloppy\Analysis\Analyzer::class)
        ->and($sloppy->files())->toBeInstanceOf(Heyosseus\Sloppy\Support\FileFinder::class)
        ->and($sloppy->scores())->toBeInstanceOf(Heyosseus\Sloppy\Scoring\ScoreCalculator::class)
        ->and($sloppy->baselines())->toBeInstanceOf(Heyosseus\Sloppy\Baseline\BaselineManager::class)
        ->and($sloppy->git())->toBeInstanceOf(Heyosseus\Sloppy\Git\Git::class);
});

it('narrows to selected rules without touching the original', function (): void {
    app(Repository::class)->set('sloppy.framework', 'laravel');

    $sloppy = app(Sloppy::class);
    $narrowed = $sloppy->onlyRules(['SL101']);

    expect($narrowed->rules()->ids())->toBe(['SL101'])
        ->and($sloppy->rules()->count())->toBe(23);
});
