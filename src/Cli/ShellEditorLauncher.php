<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Watch\EditorLauncher;

/**
 * The real editor launcher.
 *
 * `passthru()` rather than a child process, because the two families of editor
 * want opposite things. A terminal editor -- vim, nano -- needs the terminal
 * itself, and gets it here because `passthru()` hands over this process's own
 * standard streams; started as an ordinary child process it would be handed
 * pipes and refuse to run. A windowed editor returns immediately and does not
 * care either way.
 *
 * The dashboard has already restored the terminal by the time this is called,
 * and takes it back when the editor exits.
 */
final readonly class ShellEditorLauncher implements EditorLauncher
{
    public function launch(array $command): void
    {
        passthru(implode(' ', array_map(escapeshellarg(...), $command)));
    }
}
