<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\ConfigurationLoader;

it('falls back to the packaged defaults when the project has no config', function (): void {
    $project = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/A.php' => '<?php class A {}',
    ]);

    $loader = new ConfigurationLoader($project);
    $config = $loader->load();

    // The packaged default is `app`, which does not exist here, so the PSR-4
    // root takes over.
    expect($config->paths())->toBe(['src'])
        ->and($loader->source())->toBe('package defaults');

    removeTree($project);
});

it('reads a published laravel config from the project', function (): void {
    $project = tempProject([
        'composer.json' => '{}',
        'config/sloppy.php' => '<?php return ["paths" => ["domain"], "framework" => "none"];',
        'domain/A.php' => '<?php class A {}',
    ]);

    $loader = new ConfigurationLoader($project);
    $config = $loader->load();

    expect($config->paths())->toBe(['domain'])
        ->and($config->hasFramework('laravel'))->toBeFalse()
        ->and($loader->source())->toBe('config/sloppy.php');

    removeTree($project);
});

it('prefers an explicitly given config file', function (): void {
    $project = tempProject([
        'composer.json' => '{}',
        'config/sloppy.php' => '<?php return ["paths" => ["ignored"]];',
        'custom.php' => '<?php return ["paths" => ["chosen"]];',
        'chosen/A.php' => '<?php class A {}',
    ]);

    $config = (new ConfigurationLoader($project))->load($project.'/custom.php');

    expect($config->paths())->toBe(['chosen']);

    removeTree($project);
});

it('rejects a config file that does not return an array', function (): void {
    $project = tempProject([
        'composer.json' => '{}',
        'bad.php' => '<?php return "nope";',
    ]);

    expect(fn (): mixed => (new ConfigurationLoader($project))->load($project.'/bad.php'))
        ->toThrow(RuntimeException::class, 'must return an array');

    removeTree($project);
});

it('keeps configured paths that exist rather than guessing', function (): void {
    $project = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'sloppy.php' => '<?php return ["paths" => ["app"]];',
        'app/A.php' => '<?php class A {}',
        'src/B.php' => '<?php class B {}',
    ]);

    expect((new ConfigurationLoader($project))->load()->paths())->toBe(['app']);

    removeTree($project);
});
