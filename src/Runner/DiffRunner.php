<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Output\DiffConsoleFormatter;
use Heyosseus\Sloppy\Output\DiffJsonFormatter;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Output\ReviewFormatter;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * Report what a change introduced against a git revision.
 *
 * The comparison runs from a revision to the working tree, so uncommitted and
 * untracked work is included: the point is to catch new debt before it is
 * committed, not after.
 */
final readonly class DiffRunner
{
    public function run(Sloppy $sloppy, DiffOptions $options, RunnerOutput $output): ExitCode
    {
        try {
            $sloppy = (new ConfigurationResolver)->resolve($sloppy, $options);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        if (! $sloppy->configuration->enabled()) {
            $output->info('Sloppy is disabled (sloppy.enabled is false).');

            return ExitCode::Success;
        }

        $git = $sloppy->git();

        if (! $git->isAvailable()) {
            $output->error('git is not available on PATH, so diff mode cannot run.');

            return ExitCode::Error;
        }

        if (! $git->isRepository()) {
            $output->error(sprintf('%s is not a git repository.', $sloppy->configuration->basePath));

            return ExitCode::Error;
        }

        if (! $git->revisionExists($options->base)) {
            $output->error(sprintf('Revision [%s] could not be resolved in this repository.', $options->base));

            return ExitCode::Error;
        }

        $threshold = $sloppy->configuration->failOn();

        try {
            $report = $sloppy->diff($options->base);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        // Review mode reorders the same report by risk instead of listing it
        // by file. It stays a presentation choice on purpose: the analysis, the
        // score and the exit code are identical, so a team can adopt the
        // reading order without adopting a different pass/fail rule.
        $formatter = match (true) {
            $options->format === OutputFormat::Json => new DiffJsonFormatter,
            $options->review => new ReviewFormatter(
                risk: $sloppy->risks(),
                explainRisk: $options->explainRisk,
                markdown: $options->format === OutputFormat::Markdown,
            ),
            default => new DiffConsoleFormatter(explain: $options->explain, failOn: $threshold),
        };

        $output->report($formatter->format($report), $options->format);

        return $threshold instanceof Severity && $report->newAtOrAbove($threshold) !== []
            ? ExitCode::FindingsAboveThreshold
            : ExitCode::Success;
    }
}
