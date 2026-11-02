<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\ShellEditorLauncher;

it('really runs the command it is given', function (): void {
    $root = tempProject([]);
    $marker = $root.'/opened.txt';

    (new ShellEditorLauncher)->launch([
        PHP_BINARY,
        '-n',
        '-r',
        // No double quotes: escapeshellarg() replaces them with spaces on
        // Windows, which is a thing worth knowing about the launcher too.
        sprintf('file_put_contents(%s, %s);', var_export($marker, true), var_export('opened', true)),
    ]);

    expect(is_file($marker))->toBeTrue()
        ->and(file_get_contents($marker))->toBe('opened');

    removeTree($root);
});

it('escapes every token it hands to the shell', function (): void {
    // The command line is assembled into a string and given to a shell, so a
    // token containing a metacharacter has to arrive as data. A file path
    // reaches this method and a path is not necessarily the project owner's
    // choice -- a checkout under review can name its files anything.
    $hostile = "a'; rm -rf /tmp/should-not-happen; echo '.php";
    $line = ShellEditorLauncher::commandLine(['vim', $hostile, '+42']);

    // Compared against the escaper's own output rather than against a quote
    // count, because the two platforms quote differently: single quotes on
    // POSIX, double quotes on Windows.
    expect($line)->toContain(escapeshellarg('vim'))
        ->and($line)->toContain(escapeshellarg($hostile))
        ->and($line)->toContain(escapeshellarg('+42'))
        // The hostile argument never appears in the line in its raw form.
        ->and($line)->not->toContain(' '.$hostile.' ');
});

it('does not execute a metacharacter smuggled through a path', function (): void {
    // The end-to-end version, so the assurance does not rest on string
    // inspection alone: the marker must never be written. The command prints
    // nothing, so a successful run stays silent and the test stays unrisky.
    $root = tempProject([]);
    $marker = $root.'/pwned.txt';

    (new ShellEditorLauncher)->launch([
        PHP_BINARY,
        '-n',
        '-r',
        'exit(0);',
        // A second argument that would run a command if it escaped its quoting.
        "'; file_put_contents('".$marker."', 'pwned'); echo '",
    ]);

    expect(is_file($marker))->toBeFalse('a metacharacter in an argument reached the shell');

    removeTree($root);
});
