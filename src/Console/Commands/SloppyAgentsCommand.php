<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Runner\AgentsOptions;
use Heyosseus\Sloppy\Runner\AgentsRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use InvalidArgumentException;

/**
 * `php artisan sloppy:agents install` -- make the agent check its own work,
 * every time. `uninstall` takes it all out again.
 *
 * The hooks it writes call `vendor/bin/sloppy` rather than Artisan: they run
 * after every edit, and booting the application each time would be paying for
 * a framework the analysis never uses.
 */
final class SloppyAgentsCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy:agents
        {action=install : install or uninstall}
        {--local : Write .claude/settings.local.json and CLAUDE.local.md, which stay out of git, instead of the shared files}
        {--dry-run : Say what would be written or removed, and write nothing}';

    protected $description = 'Install (or uninstall) hooks so Claude Code runs Sloppy after every edit and before it finishes';

    public function handle(Sloppy $sloppy): int
    {
        return $this->runWith(function (LaravelRunnerOutput $output) use ($sloppy): ExitCode {
            $action = $this->argument('action');

            if (! in_array($action, ['install', 'uninstall'], true)) {
                throw new InvalidArgumentException(sprintf('Unknown action [%s]. The actions are install and uninstall.', is_string($action) ? $action : ''));
            }

            $options = new AgentsOptions(
                local: $this->boolOption('local'),
                dryRun: $this->boolOption('dry-run'),
                binary: $sloppy->configuration->basePath.'/vendor/bin/sloppy',
            );

            return $action === 'uninstall'
                ? (new AgentsRunner)->uninstall($sloppy, $options, $output)
                : (new AgentsRunner)->run($sloppy, $options, $output);
        });
    }
}
