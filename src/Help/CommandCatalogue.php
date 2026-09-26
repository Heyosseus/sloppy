<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Help;

/**
 * What each command is for, in the order a person meets them.
 *
 * The wording here is its own: `list` already prints the one-line description
 * every command carries, and repeating it would add a screen without adding
 * an answer. What this says instead is when to reach for the command -- which
 * is the thing a description cannot fit. A test asserts the entries match the
 * registered commands exactly, so the wording can be free without the list
 * going stale.
 */
final readonly class CommandCatalogue
{
    /**
     * @return list<CommandSummary>
     */
    public static function entries(): array
    {
        return [
            new CommandSummary(
                CommandGroup::Everyday,
                'scan',
                'sloppy',
                'Analyse the project and rank what is worth reading first.',
                'you want the current state of the codebase.',
            ),
            new CommandSummary(
                CommandGroup::Everyday,
                'diff',
                'sloppy:diff',
                'Report what a change introduced, against a git revision.',
                'you are about to open a pull request.',
            ),
            new CommandSummary(
                CommandGroup::Everyday,
                'review',
                'sloppy:review',
                'The same change, ordered by risk rather than by file.',
                'the diff is long enough that order matters.',
            ),
            new CommandSummary(
                CommandGroup::Everyday,
                'baseline',
                'sloppy:baseline',
                'Accept what is already there, so only new findings fail.',
                'you are adopting Sloppy in a project with history.',
            ),
            new CommandSummary(
                CommandGroup::Pipeline,
                'ci',
                'sloppy:ci',
                'Analyse a change the way the surrounding CI system reports it.',
                'one step in a workflow; it reads the environment itself.',
            ),
            new CommandSummary(
                CommandGroup::Pipeline,
                'fix',
                'sloppy:fix',
                'Hand the fixable findings to Rector, then format with Pint.',
                'the report is long and some of it is mechanical.',
            ),
            new CommandSummary(
                CommandGroup::Pipeline,
                'health',
                'sloppy:health',
                'The score and what is dragging it down, from a cached snapshot.',
                'a dashboard, a menu bar or a scheduled check asks.',
            ),
            new CommandSummary(
                CommandGroup::Agents,
                'rules',
                'sloppy:rules',
                'Write this project\'s rules into CLAUDE.md, AGENTS.md and friends.',
                'you want the agent to know the rules before it writes.',
            ),
            new CommandSummary(
                CommandGroup::Agents,
                'agents',
                'sloppy:agents',
                'Hook Sloppy into Claude Code, so it checks every edit and every finish.',
                'the agent should fix its own findings before you ever see them.',
            ),
            new CommandSummary(
                CommandGroup::Everyday,
                'watch',
                'sloppy:watch',
                'Keep the score on screen, redrawing as files change.',
                'an agent is writing, and you want to see the cost as it lands.',
            ),
            new CommandSummary(
                CommandGroup::Everyday,
                'guide',
                'sloppy:help',
                'This list: what each command is for, on both surfaces.',
                'you are new here, or you forgot which command does which.',
            ),
            new CommandSummary(
                CommandGroup::Agents,
                'mcp',
                'sloppy:mcp',
                'Serve scan, diff, rules and health over the Model Context Protocol.',
                'the agent should be able to check its own work.',
            ),
        ];
    }
}
