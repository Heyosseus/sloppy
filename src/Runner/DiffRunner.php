<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Git\Git;
use Heyosseus\Sloppy\Output\DiffFormatterFactory;
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
            $sloppy = (new ConfigurationResolver)->resolve($sloppy, $options, $output)->withCoverage($options->coverage);
            (new ConfigurationResolver)->assertPathsExist($sloppy, $options);
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

        $base = $this->base($git, $options, $output);

        if ($base === null) {
            return ExitCode::Error;
        }

        $threshold = $sloppy->configuration->failOn();

        try {
            $report = $sloppy->diff($base);

            // A tree none of which parses has no score: 100 would be a claim
            // about code nobody read.
            if ((new ConfigurationResolver)->nothingParsed($sloppy, $report->errors)) {
                $output->error('None of the project\'s PHP files could be parsed, so there is no score to report.');

                return ExitCode::Error;
            }

            // Review mode reorders the same report by risk instead of listing
            // it by file. It stays a presentation choice on purpose: the
            // analysis, the score and the exit code are identical, so a team
            // can adopt the reading order without adopting a different
            // pass/fail rule.
            $rendered = (new DiffFormatterFactory(
                sloppy: $sloppy,
                explain: $options->explain,
                explainRisk: $options->explainRisk,
                failOn: $threshold,
                review: $options->review,
            ))->format($report, $options->format);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        $output->report($rendered, $options->format);

        return $threshold instanceof Severity && $report->newAtOrAbove($threshold) !== []
            ? ExitCode::FindingsAboveThreshold
            : ExitCode::Success;
    }

    /**
     * The revision the working tree is compared with, or null after saying
     * why there is none.
     */
    private function base(Git $git, DiffOptions $options, RunnerOutput $output): ?string
    {
        if (! $git->revisionExists($options->base)) {
            // Before the first commit `HEAD` names nothing, and everything in
            // the working tree is new: that is a comparison, not an error.
            if ($options->base === 'HEAD' && ! $git->hasCommits()) {
                $output->notice('The repository has no commits yet; every file is compared as new.');

                return 'HEAD';
            }

            $output->error(sprintf('Revision [%s] could not be resolved in this repository.', $options->base));

            return null;
        }

        if (! $options->mergeBase) {
            return $options->base;
        }

        $mergeBase = $git->mergeBase($options->base);

        if ($mergeBase === null) {
            $output->error(sprintf(
                'No merge base between [%s] and HEAD; a shallow clone may need more history (git fetch --deepen).',
                $options->base,
            ));

            return null;
        }

        $output->notice(sprintf('Comparing against the merge base of %s and HEAD: %s.', $options->base, substr($mergeBase, 0, 12)));

        return $mergeBase;
    }
}
