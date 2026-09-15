<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Sloppy;

/**
 * @return array<string, mixed>
 */
function manifest(string $file): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(
        (string) file_get_contents(dirname(__DIR__, 3).'/'.$file),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $decoded;
}

it('builds the phar from the same entry point Composer installs', function (): void {
    // Two entry points is how a binary starts reporting something the package
    // does not do, so the phar wraps `bin/sloppy` rather than a copy of it.
    $box = manifest('box.json.dist');

    expect($box['main'])->toBe('bin/sloppy')
        ->and($box['output'])->toBe('sloppy.phar');
});

it('leaves the tests and the docs out of the phar', function (): void {
    $box = manifest('box.json.dist');

    /** @var list<string> $excluded */
    $excluded = $box['blacklist'] ?? [];

    expect($excluded)->toContain('tests')
        ->and($excluded)->toContain('docs');
});

it('lets Composer dump the autoloader, because Box cannot see enums', function (): void {
    // Box's own class-map generator does not record enum declarations, and the
    // classmap it writes is authoritative -- so a phar built its way cannot
    // load Severity, ScoreBand, ExitCode, KeyPress or Symfony's own
    // AnsiColorMode, and dies on the first command with a class-not-found.
    // The release workflow runs `composer dump-autoload --classmap-authoritative`
    // and this tells Box to keep that autoloader rather than replace it.
    //
    // If this is ever flipped back to true, build the phar and run it before
    // believing it works.
    $box = manifest('box.json.dist');

    expect($box['dump-autoload'])->toBeFalse();
});

it('ships the vendor files Composer put there, not just the PHP ones', function (): void {
    // Keeping Composer's autoloader means Box stops auto-discovering vendor,
    // so box.json says what to include -- and a `*.php` filter there silently
    // drops symfony/console's completion Resources, which it opens at startup.
    $box = manifest('box.json.dist');

    /** @var list<array<string, mixed>> $finder */
    $finder = $box['finder'];

    expect($finder[0]['in'])->toBe('vendor')
        ->and($finder[0])->not->toHaveKey('name');
});

it('carries a version a release can be checked against', function (): void {
    // A phar outlives the checkout it was built from, which is exactly how
    // bin/sloppy once reported 0.2.0 throughout the 0.3.0 release. The release
    // workflow compares the tag against this, so it has to be readable.
    expect(Sloppy::VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
});

it('keeps the runtime dependencies small enough to ship in one file', function (): void {
    // Every runtime dependency is compiled into the phar and downloaded by
    // everyone who installs it, which is a second reason this list stays short.
    $composer = manifest('composer.json');

    /** @var array<string, string> $require */
    $require = $composer['require'];

    expect(array_keys($require))->toBe([
        'php',
        'nikic/php-parser',
        'symfony/console',
        'symfony/finder',
        'symfony/process',
    ]);
});

it('suggests ext-xml rather than requiring it', function (): void {
    // Coverage is optional. Requiring an extension for an optional feature
    // would make the phar refuse to install on a PHP build that never wanted
    // the feature at all -- and every coverage lookup already returns null
    // when the extension is missing.
    $composer = manifest('composer.json');

    expect($composer['suggest'])->toHaveKey('ext-xml')
        ->and($composer['require'])->not->toHaveKey('ext-xml');
});
