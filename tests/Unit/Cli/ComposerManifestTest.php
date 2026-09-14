<?php

declare(strict_types=1);

it('requires symfony/console at runtime and keeps illuminate dev-only', function (): void {
    /** @var array{require: array<string, string>, require-dev: array<string, string>, bin: list<string>, suggest: array<string, string>} $manifest */
    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($manifest['require'])->toHaveKey('symfony/console')
        ->and($manifest['require'])->not->toHaveKey('illuminate/console')
        ->and($manifest['require'])->not->toHaveKey('illuminate/support')
        ->and($manifest['require'])->not->toHaveKey('illuminate/contracts')
        ->and($manifest['require-dev'])->toHaveKey('illuminate/console')
        ->and($manifest['bin'])->toBe(['bin/sloppy', 'bin/sloppy-mcp'])
        ->and($manifest['suggest'])->toHaveKey('illuminate/console')
        // Rector, Pint and Filament are integrations, not dependencies: a
        // project that wants none of them must still install cleanly.
        ->and($manifest['require'])->not->toHaveKey('rector/rector')
        ->and($manifest['require'])->not->toHaveKey('laravel/pint')
        ->and($manifest['require'])->not->toHaveKey('filament/filament')
        ->and($manifest['require'])->not->toHaveKey('phpunit/phpunit');
});
