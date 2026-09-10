<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Console\Commands\Concerns\ResolvesConfiguration;
use Heyosseus\Sloppy\Console\ExitCode;
use Heyosseus\Sloppy\Output\DiffConsoleFormatter;
use Heyosseus\Sloppy\Output\DiffJsonFormatter;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * `php artisan sloppy:diff` -- review what a change introduced.
 *
 * The comparison runs from a revision to the working tree, so uncommitted and
 * untracked work is included. That is deliberate: the point is to catch new
 * debt before it is committed, not after.
 */
final class SloppyDiffCommand extends SloppyCommandBase
{
    use ResolvesConfiguration;

    protected $signature = 'sloppy:diff
        {base=HEAD : Revision to compare the working tree against, e.g. HEAD~1 or main}
        {--path=* : Analyse these paths instead of the configured ones}
        {--format=console : console or json}
        {--fail-on= : Lowest severity of NEW finding that fails the command, or "never"}
        {--min-confidence= : Drop findings below this confidence (0-100)}
        {--rule=* : Run only these rule IDs, e.g. --rule=SL101}
        {--explain : Include each rule\'s "why this matters" text}';

    protected $description = 'Report the findings a change introduced, relative to a git revision';

    public function handle(Sloppy $sloppy): int
    {
        try {
            $sloppy = $this->resolveSloppy($sloppy);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        if (! $sloppy->configuration->enabled()) {
            $this->components->info('Sloppy is disabled (sloppy.enabled is false).');

            return ExitCode::Success->value;
        }

        $base = $this->baseArgument();
        $git = $sloppy->git();

        if (! $git->isAvailable()) {
            $this->components->error('git is not available on PATH, so diff mode cannot run.');

            return ExitCode::Error->value;
        }

        if (! $git->isRepository()) {
            $this->components->error(sprintf('%s is not a git repository.', $sloppy->configuration->basePath));

            return ExitCode::Error->value;
        }

        if (! $git->revisionExists($base)) {
            $this->components->error(sprintf('Revision [%s] could not be resolved in this repository.', $base));

            return ExitCode::Error->value;
        }

        $threshold = $this->thresholdFor($sloppy->configuration);

        try {
            $format = $this->outputFormat();
            $report = $sloppy->diff($base);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        $formatter = $format === 'json'
            ? new DiffJsonFormatter
            : new DiffConsoleFormatter(explain: $this->boolOption('explain'), failOn: $threshold);

        $this->writeReport($formatter->format($report), $format);

        if ($threshold instanceof Severity && $report->newAtOrAbove($threshold) !== []) {
            return ExitCode::FindingsAboveThreshold->value;
        }

        return ExitCode::Success->value;
    }

    private function baseArgument(): string
    {
        $base = $this->argument('base');

        return is_string($base) && trim($base) !== '' ? trim($base) : 'HEAD';
    }
}
