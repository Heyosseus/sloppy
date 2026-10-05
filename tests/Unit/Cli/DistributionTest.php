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
        // Polyfills, so the phar runs on a PHP built without mbstring or ctype.
        'symfony/polyfill-ctype',
        'symfony/polyfill-mbstring',
        'symfony/process',
        // Only to read a deptrac.yaml for `sloppy architecture import`. A
        // hand-written YAML reader would be the larger risk.
        'symfony/yaml',
    ]);
});

it('suggests ext-xmlreader rather than requiring it', function (): void {
    // Coverage is optional. Requiring an extension for an optional feature
    // would make the phar refuse to install on a PHP build that never wanted
    // the feature at all -- and every coverage lookup already returns null
    // when the extension is missing.
    $composer = manifest('composer.json');

    expect($composer['suggest'])->toHaveKey('ext-xmlreader')
        ->and($composer['require'])->not->toHaveKey('ext-xmlreader');
});

it('packs every directory the code reads files from into the phar', function (): void {
    // A file read through __DIR__ is not a class the autoloader finds: if its
    // directory is missing from box.json the phar builds, and then fails the
    // first time the file is needed -- resources/rector-rules.php was exactly
    // that. Every such path is found here and checked against the list.
    $root = str_replace('\\', '/', dirname(__DIR__, 3));

    /** @var list<string> $packed */
    $packed = manifest('box.json.dist')['directories'];

    $files = [$root.'/bin/sloppy', $root.'/bin/sloppy-mcp'];

    foreach (['src', 'config', 'pest'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }

    $referenced = [];

    foreach ($files as $file) {
        preg_match_all("/(?:dirname\(__DIR__(?:,\s*(\d+))?\)|__DIR__)\s*\.\s*'(\/[^'\"]*)'/", (string) file_get_contents($file), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $base = str_starts_with($match[0], 'dirname') ? dirname(dirname($file), max(1, (int) ($match[1] ?: 1))) : dirname($file);
            $segments = [];

            foreach (explode('/', $base.$match[2]) as $segment) {
                if ($segment === '..') {
                    array_pop($segments);
                } elseif ($segment !== '.' && $segment !== '') {
                    $segments[] = $segment;
                }
            }

            $resolved = implode('/', $segments);
            $relative = str_starts_with($resolved, ltrim($root, '/').'/') ? substr($resolved, strlen(ltrim($root, '/')) + 1) : null;

            // Outside the package (the autoloader of the project it is
            // installed in), or Composer's own vendor directory: not ours.
            if ($relative === null || str_starts_with($relative, 'vendor/')) {
                continue;
            }

            $referenced[explode('/', $relative)[0]] = $file;
        }
    }

    expect($referenced)->toHaveKey('resources');

    foreach (array_keys($referenced) as $directory) {
        expect($packed)->toContain($directory);
    }
});
