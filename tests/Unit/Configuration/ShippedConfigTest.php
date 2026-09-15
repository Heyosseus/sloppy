<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\ConfigurationLoader;

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
