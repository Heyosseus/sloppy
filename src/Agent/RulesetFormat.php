<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

use InvalidArgumentException;

/**
 * Which coding agent a generated ruleset is written for.
 *
 * They differ only in the file they are read from and the sentence at the top
 * that tells the agent what the file is; the rules themselves are the same
 * rules, because they describe this project rather than the tool reading them.
 */
enum RulesetFormat: string
{
    case Claude = 'claude';
    case Cursor = 'cursor';
    case Agents = 'agents';
    case Copilot = 'copilot';
    case Windsurf = 'windsurf';
    case Boost = 'boost';
    case Markdown = 'markdown';
    case Json = 'json';

    public static function parse(string $value): self
    {
        $format = self::tryFrom(mb_strtolower(trim($value)));

        if (! $format instanceof self) {
            throw new InvalidArgumentException(sprintf(
                'Unknown ruleset format [%s]. Expected one of: %s.',
                trim($value),
                implode(', ', array_column(self::cases(), 'value')),
            ));
        }

        return $format;
    }

    /**
     * Where the agent that reads this format looks for it, relative to the
     * project root.
     */
    public function defaultFile(): string
    {
        return match ($this) {
            self::Claude => 'CLAUDE.md',
            self::Cursor => '.cursorrules',
            self::Agents => 'AGENTS.md',
            self::Copilot => '.github/copilot-instructions.md',
            self::Windsurf => '.windsurfrules',
            self::Boost => '.ai/guidelines/sloppy.blade.php',
            self::Markdown => 'sloppy-rules.md',
            self::Json => 'sloppy-rules.json',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude Code',
            self::Cursor => 'Cursor',
            self::Agents => 'AGENTS.md (Codex, Jules, Amp and others)',
            self::Copilot => 'GitHub Copilot',
            self::Windsurf => 'Windsurf',
            self::Boost => 'Laravel Boost',
            self::Markdown => 'Markdown',
            self::Json => 'JSON',
        };
    }

    /**
     * The line that tells the reader what this file is for.
     */
    public function preamble(): string
    {
        return match ($this) {
            self::Claude => 'Instructions for Claude Code working in this repository.',
            self::Cursor => 'Instructions for Cursor working in this repository.',
            self::Agents => 'Instructions for coding agents working in this repository.',
            self::Copilot => 'Instructions for GitHub Copilot working in this repository.',
            self::Windsurf => 'Instructions for Windsurf working in this repository.',
            self::Boost => 'Instructions for coding agents working in this repository.',
            self::Markdown, self::Json => 'The patterns Sloppy flags in this repository.',
        };
    }

    public function isJson(): bool
    {
        return $this === self::Json;
    }

    /**
     * Whether the whole file is ours, rather than a block merged into a file
     * someone else writes.
     *
     * Boost owns `CLAUDE.md` and `AGENTS.md` in the projects that use it, and
     * composes them from `.ai/guidelines`; a guideline file of our own there is
     * the one place our rules reach every agent without competing with Boost
     * for a file it regenerates.
     */
    public function ownsFile(): bool
    {
        return $this === self::Json || $this === self::Boost;
    }
}
