<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\DiffHunk;
use Heyosseus\Sloppy\Git\GitOutputParser;

/**
 * Git's plumbing output, read without a repository: every shape here is one
 * a real git prints, including the ones only an unusual file name produces.
 */
it('reads the added ranges of every file a diff names', function (): void {
    $diff = implode("\n", [
        'diff --git a/src/A.php b/src/A.php',
        '--- a/src/A.php',
        '+++ b/src/A.php',
        '@@ -1,2 +1,3 @@',
        '@@ -10 +11 @@',
        '+++ "b/src/Quo\"te\303\251\tx\n\r\a\b\f\v\\\\.php"',
        '@@ -0,0 +1,4 @@',
        "+++ b/src/With space.php\t",
        '@@ -0,0 +2,0 @@',
        '+++ /dev/null',
        '@@ -1,3 +0,0 @@',
        '+++ plain.php',
        'not a hunk header',
        '@@ malformed @@',
    ]);

    expect((new GitOutputParser)->hunks($diff))->toEqual([
        'src/A.php' => [new DiffHunk(1, 3), new DiffHunk(11, 1)],
        "src/Quo\"te\u{e9}\tx\n\r\x07\x08\f\v\\.php" => [new DiffHunk(1, 4)],
        'src/With space.php' => [new DiffHunk(2, 0)],
    ]);
});

it('reads an unterminated quoted path as far as it goes', function (): void {
    expect((new GitOutputParser)->hunks("+++ \"b/odd.php\n@@ -0,0 +1 @@"))->toEqual([
        'odd.php' => [new DiffHunk(1, 1)],
    ]);
});

it('reads a name-status listing, renames and copies included', function (): void {
    $output = implode("\0", ['M', 'src/A.php', 'A', 'src/B.php', 'D', 'src/C.php', 'R100', 'old.php', 'new.php', 'C075', 'from.php', 'copy.php', 'R100', 'gone.php', '', 'T', 'src/T.php', 'M', '', '']);

    expect((new GitOutputParser)->nameStatus($output))->toEqual([
        new ChangedFile('src/A.php', 'modified'),
        new ChangedFile('src/B.php', 'added'),
        new ChangedFile('src/C.php', 'deleted'),
        new ChangedFile('new.php', 'renamed', previousPath: 'old.php'),
        new ChangedFile('copy.php', 'added'),
        new ChangedFile('gone.php', 'renamed'),
        new ChangedFile('src/T.php', 'modified'),
    ]);
});

it('reads cat-file answers, and nothing for a missing or unanswered request', function (): void {
    $output = "abc blob 5\nhello\nmissing.php missing\ndef tree 3\nabc\n";

    expect((new GitOutputParser)->blobs($output, ['a.php', 'missing.php', 'dir', 'b.php']))->toBe([
        'a.php' => 'hello',
        'missing.php' => null,
        'dir' => null,
        'b.php' => null,
    ]);
});

it('reads NUL-separated listings and counts how often each path appears', function (): void {
    $parser = new GitOutputParser;

    expect($parser->paths("a.php\0 \0b.php\0"))->toBe(['a.php', 'b.php'])
        ->and($parser->pathCounts("a.php\0b.php\n\na.php\0"))->toBe(['a.php' => 2, 'b.php' => 1]);
});

it('splits paths into command lines that fit', function (): void {
    $parser = new GitOutputParser;

    expect($parser->batches([]))->toBe([])
        ->and($parser->batches(['aaaa', 'bbbb', 'cccc'], 14))->toBe([['aaaa', 'bbbb'], ['cccc']]);
});
