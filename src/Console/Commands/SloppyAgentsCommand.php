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
 * every time.
 *
 * The hooks it writes call `vendor/bin/sloppy` rather than Artisan: they run
 * after every edit, and booting the application each time would be paying for
 * a framework the analysis never uses.
 */
final class SloppyAgentsCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy:agents
        {action=install : install}
        {--local : Write .claude/settings.local.json, which stays out of git, instead of the shared settings}
        {--dry-run : Print the settings that would be written, and write nothing}';

    protected $description = 'Install hooks so Claude Code runs Sloppy after every edit and before it finishes';

    public function handle(Sloppy $sloppy): int
    {
        return $this->runWith(function (LaravelRunnerOutput $output) use ($sloppy): ExitCode {
            $action = $this->argument('action');

            if ($action !== 'install') {
                throw new InvalidArgumentException(sprintf('Unknown action [%s]. The only action is install.', is_string($action) ? $action : ''));
            }

            return (new AgentsRunner)->run($sloppy, new AgentsOptions(
                local: $this->boolOption('local'),
                dryRun: $this->boolOption('dry-run'),
                binary: $sloppy->configuration->basePath.'/vendor/bin/sloppy',
            ), $output);
        });
    }
}
