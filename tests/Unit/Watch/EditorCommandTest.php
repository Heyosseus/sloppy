<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Watch\EditorCommand;

it('prefers VISUAL over EDITOR', function (): void {
    $command = EditorCommand::from(['VISUAL' => 'code', 'EDITOR' => 'vim']);

    expect($command->for('app/A.php', 40))->toBe(['code', '-g', 'app/A.php:40']);
});

it('falls back to EDITOR', function (): void {
    expect(EditorCommand::from(['EDITOR' => 'vim'])->for('app/A.php', 40))->toBe(['vim', '+40', 'app/A.php']);
});

it('does nothing when no editor is configured', function (): void {
    // Guessing at an editor is how a dashboard ends up launching vi on
    // somebody who has never used it and cannot get out.
    expect(EditorCommand::from([])->for('app/A.php', 40))->toBeNull()
        ->and(EditorCommand::from(['EDITOR' => '   '])->for('app/A.php', 40))->toBeNull();
});

it('knows how each editor is told about a line', function (string $editor, array $expected): void {
    expect(EditorCommand::from(['EDITOR' => $editor])->for('app/A.php', 40))->toBe($expected);
})->with([
    ['vim', ['vim', '+40', 'app/A.php']],
    ['nvim', ['nvim', '+40', 'app/A.php']],
    ['nano', ['nano', '+40', 'app/A.php']],
    ['emacs', ['emacs', '+40', 'app/A.php']],
    ['code', ['code', '-g', 'app/A.php:40']],
    ['cursor', ['cursor', '-g', 'app/A.php:40']],
    ['codium', ['codium', '-g', 'app/A.php:40']],
    ['subl', ['subl', 'app/A.php:40']],
    ['phpstorm', ['phpstorm', '--line', '40', 'app/A.php']],
    ['idea', ['idea', '--line', '40', 'app/A.php']],
]);

it('just opens the file when the editor is one it does not know', function (): void {
    expect(EditorCommand::from(['EDITOR' => 'ed'])->for('app/A.php', 40))->toBe(['ed', 'app/A.php']);
});

it('recognises an editor given by path or with a Windows extension', function (): void {
    expect(EditorCommand::from(['EDITOR' => '/usr/local/bin/nvim'])->for('app/A.php', 40))
        ->toBe(['/usr/local/bin/nvim', '+40', 'app/A.php'])
        ->and(EditorCommand::from(['EDITOR' => 'code.cmd'])->for('app/A.php', 40))
        ->toBe(['code.cmd', '-g', 'app/A.php:40']);
});

it('keeps a quoted path together, which is how Program Files survives', function (): void {
    // The EDITOR convention word-splits, so a path with spaces is quoted --
    // and on Windows that is most of them.
    expect(EditorCommand::from(['EDITOR' => '"C:/Program Files/Code/code.cmd" -w'])->for('app/A.php', 40))
        ->toBe(['C:/Program Files/Code/code.cmd', '-w', '-g', 'app/A.php:40'])
        ->and(EditorCommand::from(['EDITOR' => "'/opt/my editor/vim'"])->for('app/A.php', 40))
        ->toBe(['/opt/my editor/vim', '+40', 'app/A.php']);
});

it('keeps the flags the editor was configured with', function (): void {
    expect(EditorCommand::from(['EDITOR' => 'code -w --new-window'])->for('app/A.php', 40))
        ->toBe(['code', '-w', '--new-window', '-g', 'app/A.php:40']);
});
