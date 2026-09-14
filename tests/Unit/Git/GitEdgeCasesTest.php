<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * A repository with one commit and a working tree change, for the paths git
 * only takes when something is missing or malformed.
 */
function edgeRepository(): TempRepository
{
    return TempRepository::create()
        ->write('README.md', "# Notes\n")
        ->write('src/Fine.php', "<?php\n\nclass Fine\n{\n}\n")
        ->commit('first');
}

it('answers with nothing for every file when the revision does not exist', function (): void {
    $repository = edgeRepository();

    expect($repository->client()->showFiles('v9.9.9', ['src/Fine.php']))->toBe(['src/Fine.php' => null])
        ->and($repository->client()->hunksFor('v9.9.9', 'src/Fine.php'))->toBe([])
        ->and($repository->client()->hunksForFiles('v9.9.9', ['src/Fine.php']))->toBe([]);

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('answers with null for a path that did not exist at that revision', function (): void {
    $repository = edgeRepository();

    $contents = $repository->client()->showFiles('HEAD', ['src/Fine.php', 'src/Missing.php']);

    expect($contents['src/Fine.php'])->toContain('class Fine')
        ->and($contents['src/Missing.php'])->toBeNull();

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('passes a file it cannot analyse through untouched', function (): void {
    $repository = edgeRepository()->write('README.md', "# Notes\n\nMore.\n");

    $enriched = $repository->client()->withHunks('HEAD', [
        new ChangedFile('README.md', 'modified'),
        new ChangedFile('src/Gone.php', 'deleted'),
    ]);

    expect($enriched)->toHaveCount(2)
        ->and($enriched[0]->hunks)->toBe([])
        ->and($enriched[1]->relativePath)->toBe('src/Gone.php');

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('reads hunks for many files in batches', function (): void {
    $repository = edgeRepository();
    $paths = [];

    // Enough long paths to force the command line to be split, which is the
    // only way the batching is exercised at all.
    foreach (range(1, 120) as $index) {
        $path = sprintf('src/A%03d/VeryLongDirectoryNameToPushPastTheCommandLineBudget/File%03d.php', $index, $index);
        $repository->write($path, "<?php\n\nclass File".$index."\n{\n}\n");
        $paths[] = $path;
    }

    $repository->commit('many files');

    foreach ($paths as $path) {
        $repository->write($path, "<?php\n\nclass ".basename($path, '.php')."\n{\n    public int \$added = 1;\n}\n");
    }

    $hunks = $repository->client()->hunksForFiles('HEAD', $paths);

    expect(count($hunks))->toBeGreaterThan(100);

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('reads no hunks from a new file that is empty, or gone again', function (): void {
    $repository = edgeRepository()->write('src/Empty.php', "   \n");

    $enriched = $repository->client()->withHunks('HEAD', [
        new ChangedFile('src/Empty.php', 'untracked'),
        new ChangedFile('src/Nowhere.php', 'untracked'),
    ]);

    expect($enriched[0]->hunks)->toBe([])
        ->and($enriched[1]->hunks)->toBe([]);

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('lists nothing untracked outside a repository', function (): void {
    $root = tempProject(['composer.json' => '{}']);

    expect((new Heyosseus\Sloppy\Git\Git($root))->untrackedFiles())->toBe([]);

    removeTree($root);
});
