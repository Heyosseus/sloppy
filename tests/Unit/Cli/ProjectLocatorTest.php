<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\ProjectLocator;

/**
 * What `ProjectLocator::locate()` actually promises to return: the resolved,
 * forward-slashed real path. `tempProject()` builds its root string from
 * `sys_get_temp_dir()` directly, which is not always already in that form --
 * on a machine where the OS temp directory resolves through an 8.3 short
 * name (`RRUKHA~1` rather than `rrukhadze`, say), the raw string and the
 * canonical one differ even though they name the same directory. Comparing
 * against this rather than the raw string tests what the method contracts to
 * do without being coupled to that quirk.
 */
function canonicalProjectPath(string $path): string
{
    return rtrim(str_replace('\\', '/', (string) realpath($path)), '/');
}

it('uses an explicit project path when it holds a composer.json', function (): void {
    $project = tempProject(['composer.json' => '{}']);

    expect((new ProjectLocator)->locate($project, sys_get_temp_dir()))->toBe(canonicalProjectPath($project));

    removeTree($project);
});

it('walks up from the working directory to find the project root', function (): void {
    $project = tempProject([
        'composer.json' => '{}',
        'src/Deep/Nested/Thing.php' => '<?php',
    ]);

    expect((new ProjectLocator)->locate(null, $project.'/src/Deep/Nested'))->toBe(canonicalProjectPath($project));

    removeTree($project);
});

it('refuses a directory that is not a project', function (): void {
    // tempProject() nests under the OS temp directory. On a machine whose
    // home directory happens to hold an unrelated project's composer.json
    // above that temp directory, walking up from a tempProject() root would
    // find that composer.json instead of failing, which would make this
    // test depend on what else lives on the developer's machine. Anchoring
    // the orphan directly under the filesystem root sidesteps that: nothing
    // this package ships or requires puts a composer.json there.
    $root = sys_get_temp_dir();

    while (($parent = dirname($root)) !== $root) {
        $root = $parent;
    }

    $orphan = str_replace('\\', '/', $root).'/sloppy-orphan-'.bin2hex(random_bytes(6));
    mkdir($orphan.'/src', 0o777, true);
    file_put_contents($orphan.'/src/A.php', '<?php');

    expect(fn (): string => (new ProjectLocator)->locate(null, $orphan))
        ->toThrow(RuntimeException::class, 'No composer.json found');

    removeTree($orphan);
});

it('refuses an explicit path that does not exist', function (): void {
    expect(fn (): string => (new ProjectLocator)->locate('/definitely/not/here', sys_get_temp_dir()))
        ->toThrow(RuntimeException::class, '/definitely/not/here');
});
