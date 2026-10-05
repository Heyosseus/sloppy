<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\Git;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * What a real working copy throws at the git layer: a project that is one
 * package of a larger repository, file names git quotes, a user's own diff
 * settings, a file moved without telling git, a repository with no commits.
 */
beforeEach(function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }
});

/**
 * @return array<string, ChangedFile>
 */
function changedByPath(Git $git, string $base = 'HEAD'): array
{
    $byPath = [];

    foreach ($git->changedFiles($base) as $file) {
        $byPath[$file->relativePath] = $file;
    }

    return $byPath;
}

it('sees the changes of a project in a subdirectory of the repository, by its own paths', function (): void {
    $repository = TempRepository::create()
        ->write('pkg/app/Foo.php', "<?php\n\nclass Foo\n{\n}\n")
        ->write('other/Bar.php', "<?php\n\nclass Bar\n{\n}\n")
        ->commit('first');

    $repository
        ->write('pkg/app/Foo.php', "<?php\n\nclass Foo\n{\n    public function go(): void {}\n}\n")
        ->write('other/Bar.php', "<?php\n\nclass Bar\n{\n    public int \$x = 1;\n}\n")
        ->write('pkg/app/New.php', "<?php\n\nclass NewOne\n{\n}\n");

    $git = new Git($repository->path.'/pkg');
    $changed = changedByPath($git);

    expect(array_keys($changed))->toBe(['app/Foo.php', 'app/New.php'])
        ->and($changed['app/Foo.php']->status)->toBe('modified')
        ->and($git->hunksForFiles('HEAD', ['app/Foo.php']))->toHaveKey('app/Foo.php')
        ->and($git->showFiles('HEAD', ['app/Foo.php'])['app/Foo.php'])->toBe("<?php\n\nclass Foo\n{\n}\n")
        ->and($git->prefix())->toBe('pkg/')
        ->and((new Git($repository->path))->prefix())->toBe('');

    $repository->remove();
});

it('reads file names with spaces and non-ASCII characters', function (): void {
    $repository = TempRepository::create()
        ->write('app/Café.php', "<?php\n\nclass Cafe\n{\n}\n")
        ->write('app/My Sp.php', "<?php\n\nclass MySp\n{\n}\n")
        ->commit('first');

    $repository
        ->write('app/Café.php', "<?php\n\nclass Cafe\n{\n    public int \$a = 1;\n}\n")
        ->write('app/My Sp.php', "<?php\n\nclass MySp\n{\n    public int \$a = 1;\n}\n")
        ->write('app/Ünï new.php', "<?php\n");

    $git = $repository->client();
    $changed = changedByPath($git);
    $hunks = $git->hunksForFiles('HEAD', ['app/Café.php', 'app/My Sp.php']);

    expect(array_keys($changed))->toBe(['app/Café.php', 'app/My Sp.php', 'app/Ünï new.php'])
        ->and($changed['app/Ünï new.php']->status)->toBe('untracked')
        ->and($hunks['app/Café.php'][0]->startLine)->toBe(5)
        ->and($hunks['app/My Sp.php'][0]->startLine)->toBe(5)
        ->and($git->showFiles('HEAD', ['app/My Sp.php'])['app/My Sp.php'])->toContain('class MySp');

    $repository->remove();
});

it('parses hunks whatever the user configured for diffs', function (): void {
    $repository = TempRepository::create()
        ->write('app/A.php', "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n")
        ->commit('first');
    $repository->write('app/A.php', "<?php\n\$a = 1;\n\$b = 99;\n\$c = 3;\n");

    foreach ([
        ['diff.mnemonicPrefix', 'true'],
        ['diff.noprefix', 'true'],
        ['diff.srcPrefix', 'x/'],
        ['diff.dstPrefix', 'y/'],
        ['color.diff', 'always'],
        ['color.ui', 'always'],
        ['diff.external', 'false'],
        ['diff.relative', 'false'],
    ] as [$key, $value]) {
        $repository->git(['config', $key, $value]);
    }

    $hunks = $repository->client()->hunksForFiles('HEAD', ['app/A.php']);

    expect($hunks)->toHaveKey('app/A.php')
        ->and($hunks['app/A.php'][0]->startLine)->toBe(3)
        ->and($hunks['app/A.php'][0]->lineCount)->toBe(1)
        ->and(array_keys(changedByPath($repository->client())))->toBe(['app/A.php']);

    $repository->remove();
});

it('pairs a file moved with a plain mv as a rename, without touching the index', function (): void {
    $body = "<?php\n\nclass Moved\n{\n    public function go(): string\n    {\n        return 'a value that makes the file long enough to match';\n    }\n}\n";
    $repository = TempRepository::create()->write('app/B.php', $body)->commit('first');

    $repository->delete('app/B.php')->write('app/Sub/B.php', $body);
    $statusBefore = $repository->git(['status', '--porcelain']);

    $git = $repository->client();
    $changed = changedByPath($git);
    $enriched = $git->withHunks('HEAD', array_values($changed));

    expect(array_keys($changed))->toBe(['app/Sub/B.php'])
        ->and($changed['app/Sub/B.php']->status)->toBe('renamed')
        ->and($changed['app/Sub/B.php']->previousPath)->toBe('app/B.php')
        // A move that changed nothing touched no lines.
        ->and($enriched[0]->hunks)->toBe([])
        ->and($repository->git(['status', '--porcelain']))->toBe($statusBefore);

    $repository->remove();
});

it('answers for a repository with no commits yet', function (): void {
    $repository = TempRepository::create()->write('app/A.php', "<?php\n\nclass A\n{\n}\n");
    $git = $repository->client();

    $empty = $git->emptyTree();

    expect($git->hasCommits())->toBeFalse()
        ->and($empty)->toMatch('/^[0-9a-f]{40,64}$/')
        ->and(array_map(static fn (ChangedFile $file): string => $file->status, changedByPath($git, $empty)))
        ->toBe(['app/A.php' => 'untracked']);

    $repository->remove();
});

it('finds where a branch forked, and tells a branch from a commit', function (): void {
    $repository = TempRepository::create()->write('a.txt', "1\n")->commit('first');
    $fork = trim($repository->git(['rev-parse', 'HEAD']));
    $repository->git(['checkout', '-q', '-b', 'feature']);
    $repository->write('b.txt', "2\n")->commit('feature work');
    $repository->git(['checkout', '-q', 'main']);
    $repository->write('c.txt', "3\n")->commit('main moved on');
    $repository->git(['checkout', '-q', 'feature']);

    $git = $repository->client();

    expect($git->mergeBase('main'))->toBe($fork)
        ->and($git->isRef('main'))->toBeTrue()
        ->and($git->isRef($fork))->toBeFalse()
        ->and($git->commitOf('main'))->not->toBe($fork)
        ->and($git->mergeBase('no-such-branch'))->toBeNull();

    $repository->remove();
});
