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
