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
    $orphan = tempProject(['src/A.php' => '<?php']);

    // The refusal is only reachable when nothing above the orphan holds a
    // composer.json, and that depends on the machine: a developer's home
    // directory may contain one, while CI's temp directory has no such
    // ancestor. So check, and skip with the offending path named rather than
    // pretend. An earlier version of this test anchored the fixture at the
    // filesystem root to dodge the problem, which CI cannot even create --
    // `mkdir(): Permission denied`.
    $ancestor = dirname($orphan);

    // The same number of levels ProjectLocator itself walks, so the skip
    // condition matches the behaviour under test rather than being stricter.
    for ($depth = 0; $depth < 12; $depth++) {
        if (is_file($ancestor.'/composer.json')) {
            removeTree($orphan);

            $this->markTestSkipped(sprintf(
                'An unrelated %s/composer.json sits above the temp directory, so the walk-up cannot fail here.',
                str_replace('\\', '/', $ancestor),
            ));
        }

        $parent = dirname($ancestor);

        if ($parent === $ancestor) {
            break;
        }

        $ancestor = $parent;
    }

    expect(fn (): string => (new ProjectLocator)->locate(null, $orphan))
        ->toThrow(RuntimeException::class, 'No composer.json found');

    removeTree($orphan);
});

it('refuses an explicit path that does not exist', function (): void {
    expect(fn (): string => (new ProjectLocator)->locate('/definitely/not/here', sys_get_temp_dir()))
        ->toThrow(RuntimeException::class, '/definitely/not/here');
});

it('refuses a URL-shaped project path rather than treating it as absolute', function (string $url): void {
    // There is no remote project here. A value like this used to be classified
    // as an absolute path and passed through validation untouched, which was
    // harmless only because realpath() and is_dir() happen to reject it. The
    // resolution must not depend on that: the value is refused, and refused as
    // something that is not a directory here.
    expect(fn (): string => (new ProjectLocator)->locate($url, sys_get_temp_dir()))
        ->toThrow(RuntimeException::class, 'does not exist');
})->with([
    'http' => 'http://example.com',
    'https' => 'https://example.com/project',
    'a file stream' => 'file:///etc/passwd',
    'php' => 'php://stdin',
]);

it('resolves a relative path that merely contains a colon', function (): void {
    // The removed clause matched on `://` anywhere in the string. A relative
    // directory whose name contains a colon is a legitimate path on POSIX, and
    // must still resolve against the given working directory rather than being
    // mistaken for a URL. Windows cannot create such a directory, so the case
    // is asserted there only through the classifier's observable behaviour.
    if (DIRECTORY_SEPARATOR === '\\') {
        // On Windows a colon cannot appear in a directory name; the important
        // half -- that a URL-shaped value is refused -- is covered above.
        expect(fn (): string => (new ProjectLocator)->locate('weird:name', sys_get_temp_dir()))
            ->toThrow(RuntimeException::class);

        return;
    }

    $workspace = tempProject([
        'composer.json' => '{}',
        'weird:name/composer.json' => '{}',
    ]);

    expect((new ProjectLocator)->locate('weird:name', $workspace))->toBe(canonicalProjectPath($workspace.'/weird:name'));

    removeTree($workspace);
});

it('takes a relative project path relative to the working directory it was given, not the process', function (): void {
    $workspace = tempProject([
        'composer.json' => '{}',
        'packages/billing/composer.json' => '{}',
    ]);

    // The process is elsewhere; only the given working directory makes the
    // relative path resolvable.
    expect(getcwd())->not->toBe($workspace)
        ->and((new ProjectLocator)->locate('packages/billing', $workspace))->toBe(canonicalProjectPath($workspace.'/packages/billing'))
        ->and((new ProjectLocator)->locate('./packages/billing', $workspace))->toBe(canonicalProjectPath($workspace.'/packages/billing'));

    removeTree($workspace);
});
