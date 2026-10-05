<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Configuration\ConfigurationLoader;
use Heyosseus\Sloppy\Rules\Architecture\AbstractionInflationRule;
use Heyosseus\Sloppy\Support\FileFinder;

it('loads the shipped defaults without a framework in the room', function (): void {
    // The standalone binary and the phar have no illuminate/support, so the
    // config this package ships must not call a Laravel helper to be read.
    // `vendor/bin/sloppy` in a framework-free project hits this too.
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/config/sloppy.php');

    expect($source)->not->toMatch('/^\s*\x27enabled\x27\s*=>\s*env\(/m');
});

it('falls back to the package defaults when the project has no config file', function (): void {
    $root = tempProject(['composer.json' => '{}']);
    $loader = new ConfigurationLoader($root);

    expect($loader->load()->enabled())->toBeTrue()
        ->and($loader->source())->toBe('package defaults');

    removeTree($root);
});

it('ships the suppression vocabulary so new tools need no release', function (): void {
    // Mago, Psalm and PHPCS all spell this differently, and more will exist.
    // A user adopting one should edit config, not wait for us.
    $config = require dirname(__DIR__, 3).'/config/sloppy.php';

    expect($config['rules']['SL501']['annotations'])
        ->toContain('@phpstan-ignore')
        ->toContain('@psalm-suppress')
        ->toContain('@mago-expect');
});

it('excludes test directories by default, including inside modules', function (): void {
    // Long setup, many public methods and near-identical bodies are what good
    // tests look like, so the architecture and size rules misread them.
    $config = require dirname(__DIR__, 3).'/config/sloppy.php';
    $finder = new FileFinder(Configuration::fromArray([
        'paths' => ['app'],
        'exclude' => $config['exclude'],
    ], '/project'));

    expect($finder->isExcluded('tests/Feature/CheckoutTest.php'))->toBeTrue()
        ->and($finder->isExcluded('Modules/Billing/Tests/Unit/ChargeTest.php'))->toBeTrue()
        ->and($finder->isExcluded('Modules/Billing/app/Models/Charge.php'))->toBeFalse();
});

it('excludes migrations, factories and seeders in module layouts by default', function (): void {
    // nwidart modules keep them under Modules/<Name>/Database/..., which the
    // lower-case database/migrations entry does not match.
    $config = require dirname(__DIR__, 3).'/config/sloppy.php';
    $finder = new FileFinder(Configuration::fromArray([
        'paths' => ['app'],
        'exclude' => $config['exclude'],
    ], '/project'));

    expect($finder->isExcluded('Modules/Billing/Database/Migrations/2024_01_01_000000_backfill.php'))->toBeTrue()
        ->and($finder->isExcluded('Modules/Billing/Database/Seeders/BillingSeeder.php'))->toBeTrue()
        ->and($finder->isExcluded('Modules/Billing/Database/Factories/ChargeFactory.php'))->toBeTrue()
        ->and($finder->isExcluded('Modules/Billing/database/migrations/2024_01_01_000000_backfill.php'))->toBeTrue()
        ->and($finder->isExcluded('Modules/Billing/app/Models/Charge.php'))->toBeFalse();
});

it('ships the same SL301 convention bases the rule defaults to', function (): void {
    // A project that copies the config to add its own base must start from
    // the full default list, not a stale one.
    $config = require dirname(__DIR__, 3).'/config/sloppy.php';

    expect($config['rules']['SL301']['convention_bases'])->toBe(AbstractionInflationRule::CONVENTION_BASES);
});

it('ships the full default SL102 framework bases', function (): void {
    $config = require dirname(__DIR__, 3).'/config/sloppy.php';

    expect($config['rules']['SL102']['framework_bases'])->toBe(Heyosseus\Sloppy\Rules\Php\GodClassRule::FRAMEWORK_BASES);
});

it('ships the same SL301 layer suffixes the rule defaults to', function (): void {
    $config = require dirname(__DIR__, 3).'/config/sloppy.php';

    expect($config['rules']['SL301']['layer_suffixes'])->toBe(Heyosseus\Sloppy\Rules\Architecture\LayerStack::LAYER_SUFFIXES)
        ->and($config['rules']['SL303']['layer_suffixes'])->toBe(Heyosseus\Sloppy\Rules\Architecture\LayerStack::LAYER_SUFFIXES)
        ->and($config['rules']['SL303']['layer_stack_depth'])->toBe(3);
});

it('documents every option a rule reads in the shipped config', function (): void {
    // The published file is the reference for rule options. An option a rule
    // reads but the file never mentions is one nobody can discover.
    $config = require dirname(__DIR__, 3).'/config/sloppy.php';
    $missing = [];

    foreach (glob(dirname(__DIR__, 3).'/src/Rules/*/*Rule.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match("/function id\(\): string\s*\{\s*return '(SL\d{3})';/", $source, $id) !== 1) {
            continue;
        }

        preg_match_all("/(?:int|float|bool|list|string)Option\('([a-z_]+)'/", $source, $keys);

        foreach (array_unique($keys[1]) as $key) {
            if (! array_key_exists($key, $config['rules'][$id[1]] ?? [])) {
                $missing[] = $id[1].'.'.$key;
            }
        }
    }

    expect($missing)->toBe([]);
});
