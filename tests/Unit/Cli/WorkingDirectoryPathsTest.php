<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\WorkingDirectoryPaths;

function realRoot(string $path): string
{
    return rtrim(str_replace('\\', '/', (string) realpath($path)), '/');
}

function subdirectoryProject(): string
{
    return tempProject([
        'composer.json' => '{}',
        'app/Models/User.php' => '<?php',
        'app/Services/Billing.php' => '<?php',
        'src/Thing.php' => '<?php',
    ]);
}

it('leaves paths alone when the command runs from the project root', function (): void {
    $project = subdirectoryProject();
    $root = realRoot($project);

    expect((new WorkingDirectoryPaths)->resolve(['src', '.'], $root, $project))->toBe(['src', '.']);

    removeTree($project);
});

it('reads . in a subdirectory as that subdirectory', function (): void {
    $project = subdirectoryProject();
    $root = realRoot($project);

    expect((new WorkingDirectoryPaths)->resolve(['.'], $root, $project.'/app'))->toBe(['app']);

    removeTree($project);
});

it('reads a path relative to the subdirectory it was typed in', function (): void {
    $project = subdirectoryProject();
    $root = realRoot($project);

    expect((new WorkingDirectoryPaths)->resolve(['Models', 'Services/Billing.php'], $root, $project.'/app'))
        ->toBe(['app/Models', 'app/Services/Billing.php']);

    removeTree($project);
});

it('keeps a root-relative path that does not exist from the subdirectory', function (): void {
    $project = subdirectoryProject();
    $root = realRoot($project);

    expect((new WorkingDirectoryPaths)->resolve(['src'], $root, $project.'/app'))->toBe(['src']);

    removeTree($project);
});

it('follows .. back out of the subdirectory', function (): void {
    $project = subdirectoryProject();
    $root = realRoot($project);

    expect((new WorkingDirectoryPaths)->resolve(['../../src'], $root, $project.'/app/Models'))->toBe(['src']);

    removeTree($project);
});

it('leaves absolute paths alone', function (): void {
    $project = subdirectoryProject();
    $root = realRoot($project);

    expect((new WorkingDirectoryPaths)->resolve([$root.'/src'], $root, $project.'/app'))->toBe([$root.'/src']);

    removeTree($project);
});
