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
     * gets the binary that ran the installer. `php` is spelled out because the
     * binary's shebang means nothing to Windows.
     */
    public function command(string $basePath, string $runningBinary): string
    {
        if (is_file($basePath.'/vendor/bin/sloppy')) {
            return 'php "$CLAUDE_PROJECT_DIR/vendor/bin/sloppy"';
        }

        return sprintf('php "%s"', str_replace('\\', '/', $runningBinary));
    }
}
