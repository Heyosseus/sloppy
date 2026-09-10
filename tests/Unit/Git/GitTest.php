<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\DiffHunk;
use Heyosseus\Sloppy\Git\Git;
use Heyosseus\Sloppy\Git\GitException;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

beforeEach(function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }
});

it('recognises a repository and a non-repository', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php class A {}')->commit('first');

    $outside = tempProject(['app/A.php' => '<?php']);

    expect($repository->client()->isAvailable())->toBeTrue()
        ->and($repository->client()->isRepository())->toBeTrue()
        ->and((new Git($outside))->isRepository())->toBeFalse();

    $repository->remove();
    removeTree($outside);
});

it('resolves revisions that exist and rejects those that do not', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php class A {}')->commit('first');

    $git = $repository->client();

    expect($git->revisionExists('HEAD'))->toBeTrue()
        ->and($git->revisionExists('main'))->toBeTrue()
        ->and($git->revisionExists('does-not-exist'))->toBeFalse()
        ->and($git->currentBranch())->toBe('main');

    $repository->remove();
});

it('reads a file as it was at a revision', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php class Old {}')->commit('first');
    $repository->write('app/A.php', '<?php class New {}')->commit('second');

    $git = $repository->client();

    expect($git->showFile('HEAD~1', 'app/A.php'))->toContain('class Old')
        ->and($git->showFile('HEAD', 'app/A.php'))->toContain('class New')
        ->and($git->showFile('HEAD', 'app/Nope.php'))->toBeNull();

    $repository->remove();
});

it('counts every line of a new file as touched', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/Kept.php', "<?php\nclass Kept {}\n")->commit('first');

    // Five lines, and all five of them are new against the base.
    $repository->write('app/Fresh.php', "<?php\n\nclass Fresh\n{\n}\n");

    $git = $repository->client();
    $changed = $git->changedFiles('HEAD');

    expect($changed)->toHaveCount(1)
        ->and($changed[0]->relativePath)->toBe('app/Fresh.php')
        // Hunks are filled in on request, so the bare listing carries none.
        ->and($changed[0]->changedLineCount())->toBe(0)
        ->and($git->withHunks('HEAD', $changed[0])->changedLineCount())->toBe(5);

    $repository->remove();
});

it('lists modified, added, deleted and untracked files', function (): void {
    $repository = TempRepository::create();
    $repository
        ->write('app/Modified.php', "<?php\nclass Modified {}\n")
        ->write('app/Deleted.php', "<?php\nclass Deleted {}\n")
        ->commit('first');

    $repository
        ->write('app/Modified.php', "<?php\nclass Modified { public function go(): void {} }\n")
        ->write('app/Added.php', "<?php\nclass Added {}\n")
        ->delete('app/Deleted.php');

    // Added.php is staged; Untracked.php is not, and must still be seen.
    $repository->git(['add', 'app/Added.php']);
    $repository->write('app/Untracked.php', "<?php\nclass Untracked {}\n");

    $changed = $repository->client()->changedFiles('HEAD');
    $byPath = [];

    foreach ($changed as $file) {
        $byPath[$file->relativePath] = $file->status;
    }

    expect($byPath)->toBe([
        'app/Added.php' => 'added',
        'app/Deleted.php' => 'deleted',
        'app/Modified.php' => 'modified',
        'app/Untracked.php' => 'untracked',
    ]);

    $repository->remove();
});

it('reports the changed line ranges of a modified file', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/A.php', "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n")->commit('first');
    $repository->write('app/A.php', "<?php\n\$a = 1;\n\$b = 99;\n\$c = 3;\n");

    $hunks = $repository->client()->hunksFor('HEAD', 'app/A.php');

    expect($hunks)->toHaveCount(1)
        ->and($hunks[0]->startLine)->toBe(3)
        ->and($hunks[0]->contains(3))->toBeTrue()
        ->and($hunks[0]->contains(4))->toBeFalse();

    $repository->remove();
});

it('follows a rename to its new path', function (): void {
    $repository = TempRepository::create();
    $body = "<?php\n\nclass Renamed\n{\n    public function go(): string\n    {\n        return 'a value that makes the file long enough to match';\n    }\n}\n";
    $repository->write('app/Before.php', $body)->commit('first');
    $repository->git(['mv', 'app/Before.php', 'app/After.php']);

    $changed = $repository->client()->changedFiles('HEAD');
    $paths = array_map(static fn (ChangedFile $f): string => $f->relativePath, $changed);

    expect($paths)->toContain('app/After.php');

    $repository->remove();
});

it('throws with the git error when a command fails', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php')->commit('first');

    expect(fn (): array => $repository->client()->changedFiles('no-such-revision'))
        ->toThrow(GitException::class, 'git diff');

    $repository->remove();
});

it('returns null rather than throwing for an attempted command', function (): void {
    $repository = TempRepository::create();
    $repository->write('app/A.php', '<?php')->commit('first');

    expect($repository->client()->attempt(['rev-parse', 'nope']))->toBeNull()
        ->and($repository->client()->attempt(['--version']))->toContain('git version');

    $repository->remove();
});

describe('a changed file', function (): void {
    it('treats every line of an added file as touched', function (): void {
        $added = new ChangedFile('app/A.php', 'added');

        expect($added->touches(1))->toBeTrue()
            ->and($added->touches(9999))->toBeTrue()
            ->and($added->existedBefore())->toBeFalse();
    });

    it('reports touched lines from its hunks', function (): void {
        $modified = new ChangedFile('app/A.php', 'modified', [
            new DiffHunk(10, 3),
            new DiffHunk(40, 1),
        ]);

        expect($modified->touches(10))->toBeTrue()
            ->and($modified->touches(12))->toBeTrue()
            ->and($modified->touches(13))->toBeFalse()
            ->and($modified->touches(40))->toBeTrue()
            ->and($modified->changedLineCount())->toBe(4)
            ->and($modified->existedBefore())->toBeTrue();
    });

    it('knows which files are worth analysing', function (): void {
        expect((new ChangedFile('app/A.php', 'modified'))->isAnalysable())->toBeTrue()
            ->and((new ChangedFile('app/A.php', 'deleted'))->isAnalysable())->toBeFalse()
            ->and((new ChangedFile('README.md', 'modified'))->isAnalysable())->toBeFalse();
    });

    it('handles a hunk that removed everything', function (): void {
        $hunk = new DiffHunk(5, 0);

        expect($hunk->contains(5))->toBeFalse()
            ->and($hunk->endLine())->toBe(5);
    });
});
