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
 *
 * Every token goes through {@see escapeshellarg()} because the command line is
 * assembled into a string and handed to a shell. A file path reaches this
 * method, and a path is not necessarily something the person running Sloppy
 * chose -- a checkout under review can name its files anything. The escaping
 * is kept inside {@see commandLine()}, next to the `implode()`, so a later
 * change cannot assemble the line some other way and leave the protection
 * behind.
 */
final readonly class ShellEditorLauncher implements EditorLauncher
{
    public function launch(array $command): void
    {
        passthru(self::commandLine($command));
    }

    /**
     * A token list as a shell command line, with every token escaped.
     *
     * @param  list<string>  $command
     */
    public static function commandLine(array $command): string
    {
        return implode(' ', array_map(escapeshellarg(...), $command));
    }
}
