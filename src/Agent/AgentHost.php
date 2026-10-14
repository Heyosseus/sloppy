<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

use InvalidArgumentException;

/**
 * A coding agent whose own hooks can run Sloppy.
 *
 * Claude Code, Gemini CLI and Codex share one hook contract, near enough: a
 * settings file mapping events to matcher groups of commands, JSON on
 * standard input, and a JSON decision on standard output that sends the
 * agent back to work. What differs -- the file, the event and tool names,
 * how the project directory reaches the command, how a finding is handed to
 * the model after an edit -- is answered here, so the runner the hooks call
 * does not change per agent.
 */
enum AgentHost: string
{
    case ClaudeCode = 'claude';
    case Gemini = 'gemini';
    case Codex = 'codex';

    public static function parse(string $value): self
    {
        $host = self::tryFrom(mb_strtolower(trim($value)));

        if (! $host instanceof self) {
            throw new InvalidArgumentException(sprintf(
                'Unknown agent [%s]. Expected one of: %s.',
                trim($value),
                implode(', ', array_column(self::cases(), 'value')),
            ));
        }

        return $host;
    }

    public function label(): string
    {
        return match ($this) {
            self::ClaudeCode => 'Claude Code',
            self::Gemini => 'Gemini CLI',
            self::Codex => 'Codex',
        };
    }

    /**
     * Where the hooks are written, relative to the project root.
     *
     * The shared file is committed, so the whole team's agents are checked the
     * same way; the local one is for trying it out without deciding that for
     * anyone else. Only Claude Code has one: see {@see hasLocalFile()}.
     */
    public function settingsFile(bool $local = false): string
    {
        return match ($this) {
            self::ClaudeCode => $local ? '.claude/settings.local.json' : '.claude/settings.json',
            self::Gemini => '.gemini/settings.json',
            self::Codex => '.codex/hooks.json',
        };
    }

    /**
     * Whether the agent reads a personal, uncommitted settings file inside
     * the project. Gemini CLI and Codex do not: their only other files are
     * the user's own, which would run the hooks in every project on the
     * machine.
     */
    public function hasLocalFile(): bool
    {
        return $this === self::ClaudeCode;
    }

    /**
     * The event the agent fires after a tool has run.
     */
    public function editEvent(): string
    {
        return $this === self::Gemini ? 'AfterTool' : 'PostToolUse';
    }

    /**
     * The tools that edit files, as the matcher for {@see editEvent()}.
     * Codex edits through `apply_patch`, which it also answers to as `Edit`
     * and `Write`.
     */
    public function editTools(): string
    {
        return match ($this) {
            self::ClaudeCode => 'Edit|Write|MultiEdit',
            self::Gemini => 'write_file|replace',
            self::Codex => 'apply_patch|Edit|Write',
        };
    }

    /**
     * The event the agent fires when it is about to finish.
     */
    public function stopEvent(): string
    {
        return $this === self::Gemini ? 'AfterAgent' : 'Stop';
    }

    /**
     * The ruleset this agent reads, or null for Claude Code, whose default
     * (`CLAUDE.md`, or Boost's guidelines) the rules command already picks.
     */
    public function rulesetFormat(): ?RulesetFormat
    {
        return match ($this) {
            self::ClaudeCode => null,
            self::Gemini => RulesetFormat::Gemini,
            self::Codex => RulesetFormat::Agents,
        };
    }

    /**
     * How the hooks invoke Sloppy, without the `hook <event>` part.
     *
     * A project with Sloppy installed gets a path through the agent's own
     * project variable, so the committed file works on every checkout. A
     * global or phar install has nothing inside the project to point at, so it
     * gets the binary that ran the installer -- a path on this machine, which
     * the runner only ever writes to Claude Code's personal settings file. PHP
     * is spelled out because the binary's shebang means nothing to Windows: as
     * `php` in a shared file, and as this machine's own PHP in a personal one.
     *
     * Claude's variable is braced because Claude Code runs hooks through
     * PowerShell on Windows when it cannot find Git Bash, and PowerShell
     * reads a bare `$CLAUDE_PROJECT_DIR` as an undefined variable of its own:
     * the command became `php "/vendor/bin/sloppy"`. Claude Code substitutes
     * the braced form itself, and Bash expands it the same way. Gemini CLI
     * substitutes only the bare `$GEMINI_PROJECT_DIR`. Codex has no project
     * variable at all and runs hooks from the directory the session started
     * in, which for a project it trusts is the project root.
     */
    public function command(string $basePath, string $runningBinary, bool $local = false, string $phpBinary = PHP_BINARY): string
    {
        $php = $local ? $this->php($phpBinary) : 'php';

        if (! $this->hasProjectCopy($basePath)) {
            return sprintf('%s "%s"', $php, str_replace('\\', '/', $runningBinary));
        }

        return match ($this) {
            self::ClaudeCode => $php.' "${CLAUDE_PROJECT_DIR}/vendor/bin/sloppy"',
            self::Gemini => $php.' "$GEMINI_PROJECT_DIR/vendor/bin/sloppy"',
            self::Codex => $php.' vendor/bin/sloppy',
        };
    }

    /**
     * The whole command one hook runs. Claude Code's carries no `--host`,
     * so hooks written before there was a choice still mean the same.
     */
    public function hookCommand(string $command, HookEvent $event): string
    {
        $hook = $command.' hook '.$event->value;

        return $this === self::ClaudeCode ? $hook : $hook.' --host='.$this->value;
    }

    /**
     * What goes on standard output to hand findings to the model.
     *
     * Before finishing, every host takes a block decision whose reason
     * becomes the agent's next instruction. After an edit, Claude Code and
     * Codex take one too. Gemini CLI would put the reason in place of the
     * tool's result, so the model could no longer tell that its write went
     * through; additional context is appended to the result instead.
     *
     * @return array<string, mixed>
     */
    public function blockOutput(HookEvent $event, string $feedback): array
    {
        if ($this === self::Gemini && $event === HookEvent::PostEdit) {
            return ['hookSpecificOutput' => ['hookEventName' => $this->editEvent(), 'additionalContext' => $feedback]];
        }

        return ['decision' => 'block', 'reason' => $feedback];
    }

    /**
     * Whether a notice for the person may go on standard output. Claude
     * Code shows plain text there in the transcript; Gemini CLI and Codex
     * read standard output as the JSON decision and nothing else, so their
     * notices go to standard error.
     */
    public function noticesOnStdout(): bool
    {
        return $this === self::ClaudeCode;
    }

    /**
     * Whether the project has its own Sloppy to point the hooks at. Without
     * one, a hook names a path on this machine, which belongs in a personal
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
