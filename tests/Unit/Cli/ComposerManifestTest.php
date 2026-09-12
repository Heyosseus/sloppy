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
        ->and($manifest['bin'])->toBe(['bin/sloppy'])
        ->and($manifest['suggest'])->toHaveKey('illuminate/console');
});
