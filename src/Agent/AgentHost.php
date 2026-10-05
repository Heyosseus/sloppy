<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

/**
 * A coding agent whose own hooks can run Sloppy.
 *
 * Only Claude Code for now: its hook contract -- JSON on standard input, exit
 * 2 to hand standard error back to the model -- is the one worth building on.
 * Another agent is another case here and another settings writer, not a
 * change to the runner the hooks call.
 */
enum AgentHost: string
{
    case ClaudeCode = 'claude';

    public function label(): string
    {
        return 'Claude Code';
    }

    /**
     * Where the hooks are written, relative to the project root.
     *
     * The shared file is committed, so the whole team's agents are checked the
     * same way; the local one is for trying it out without deciding that for
     * anyone else.
     */
    public function settingsFile(bool $local): string
    {
        return $local ? '.claude/settings.local.json' : '.claude/settings.json';
    }

    /**
     * How the hooks invoke Sloppy, without the `hook <event>` part.
     *
     * A project with Sloppy installed gets a path through the agent's own
     * project variable, so the committed file works on every checkout. A
     * global or phar install has nothing inside the project to point at, so it
     * gets the binary that ran the installer -- a path on this machine, which
     * the runner only ever writes to the personal settings file. PHP is
     * spelled out because the binary's shebang means nothing to Windows: as
     * `php` in a shared file, and as this machine's own PHP in a personal one.
     *
     * The variable is braced because Claude Code runs hooks through
     * PowerShell on Windows when it cannot find Git Bash, and PowerShell
     * reads a bare `$CLAUDE_PROJECT_DIR` as an undefined variable of its own:
     * the command became `php "/vendor/bin/sloppy"`. Claude Code substitutes
     * the braced form itself, and Bash expands it the same way.
     */
    public function command(string $basePath, string $runningBinary, bool $local = false, string $phpBinary = PHP_BINARY): string
    {
        $php = $local ? $this->php($phpBinary) : 'php';

        if ($this->hasProjectCopy($basePath)) {
            return $php.' "${CLAUDE_PROJECT_DIR}/vendor/bin/sloppy"';
        }

        return sprintf('%s "%s"', $php, str_replace('\\', '/', $runningBinary));
    }

    /**
     * Whether the project has its own Sloppy to point the hooks at. Without
     * one, a hook names a path on this machine, which belongs in the personal
     * settings file and never in the one the team shares.
     */
    public function hasProjectCopy(string $basePath): bool
    {
        return is_file($basePath.'/vendor/bin/sloppy');
    }

    /**
     * The PHP that is running now, for a personal settings file: a shared one
     * has to say `php` and trust the PATH of every machine it is checked out
     * on, but this machine's own PHP is known, and may not be the one first
     * on its PATH. A path with a space in it would have to be quoted, which
     * PowerShell then reads as a string rather than a command, so that one
     * falls back to `php` too.
     */
    private function php(string $binary): string
    {
        $binary = str_replace('\\', '/', $binary);

        return $binary === '' || preg_match('/\s/', $binary) === 1 ? 'php' : $binary;
    }
}
