<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ci\BaseRevision;
use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Ci\CiProvider;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Output\DiffConsoleFormatter;
use Heyosseus\Sloppy\Output\DiffGithubFormatter;
use Heyosseus\Sloppy\Output\DiffJsonFormatter;
use Heyosseus\Sloppy\Output\FormatterFactory;
use Heyosseus\Sloppy\Output\MarkdownFormatter;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Output\ReviewFormatter;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * One command that does the right thing inside a pipeline.
 *
 * `sloppy diff --format=github --fail-on=high` works, and every team adding it
 * has to know which revision CI checked out, which format their provider
 * renders, where the job summary goes and how to hand a score back to the
 * workflow. Four decisions, made the same way by everyone, and wrong in a
 * quiet way when they are made differently -- so they are made here instead,
 * and the YAML is three lines.
 *
 * What it decides:
 *
 * - GitHub gets annotations on the changed lines, so a review comment lands
 *   where the code is. A pull request is compared against its target branch.
 * - GitLab gets a whole-project Code Quality artifact, because GitLab's own
 *   merge request widget works out which findings are new by comparing the
 *   report against the target branch's. Handing it a diff would break that.
 * - Anywhere else gets the console report a human is watching.
 */
final readonly class CiRunner
{
    public function __construct(private CiEnvironment $environment = new CiEnvironment) {}

    public function run(Sloppy $sloppy, CiOptions $options, RunnerOutput $output): ExitCode
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

        $provider = $this->environment->provider();
        $format = $options->format ?? $provider->defaultFormat();

        $output->notice(sprintf('Detected %s; reporting as %s.', $provider->label(), $format->value));

        try {
            $base = $this->base($sloppy, $options, $provider, $output);

            return $base === null
                ? $this->scan($sloppy, $options, $format, $output)
                : $this->diff($sloppy, $options, $format, $base, $output);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }
    }

    /**
     * The revision to compare against, or null to analyse the whole project.
     */
    private function base(Sloppy $sloppy, CiOptions $options, CiProvider $provider, RunnerOutput $output): ?string
    {
        if ($options->scan) {
            return null;
        }

        if ($options->base === null && $provider === CiProvider::GitLab) {
            $output->notice('Scanning the whole project: GitLab compares Code Quality reports itself.');

            return null;
        }

        $git = $sloppy->git();

        if (! $git->isAvailable() || ! $git->isRepository()) {
            $output->notice('Scanning the whole project: this is not a git checkout.');

            return null;
        }

        $base = BaseRevision::resolve($git, $options->base, $this->environment);

        if ($base === null) {
            $output->notice(sprintf(
                'Scanning the whole project: none of [%s] resolve here.',
                implode(', ', BaseRevision::candidates($options->base, $this->environment)) ?: 'no candidate',
            ));
        }

        return $base;
    }

    private function diff(Sloppy $sloppy, CiOptions $options, OutputFormat $format, string $base, RunnerOutput $output): ExitCode
    {
        $report = $sloppy->diff($base);
        $threshold = $sloppy->configuration->failOn();
        $failed = $threshold instanceof Severity && $report->newAtOrAbove($threshold) !== [];

        $written = $this->emit(
            $this->diffReport($sloppy, $report, $format),
            (new DiffConsoleFormatter(failOn: $threshold))->format($report),
            $format,
            $options,
            $sloppy,
            $output,
        );

        $this->summary(
            (new ReviewFormatter(risk: $sloppy->risks(), markdown: true))->format($report),
            $options,
            $output,
        );

        $this->outputs([
            'mode' => 'diff',
            'base' => $base,
            'score' => $report->currentScore->value,
            'score-delta' => $report->scoreDelta(),
            'findings' => count($report->new) + count($report->existing),
            'new-findings' => count($report->new),
            'resolved-findings' => count($report->resolved),
            'status' => $failed ? 'failed' : 'passed',
        ], $output);

        if (! $written) {
            return ExitCode::Error;
        }

        return $failed ? ExitCode::FindingsAboveThreshold : ExitCode::Success;
    }

    private function scan(Sloppy $sloppy, CiOptions $options, OutputFormat $format, RunnerOutput $output): ExitCode
    {
        $result = (new BaselineFilter)->apply($sloppy, $sloppy->analyze(), $output, ! $options->noBaseline);
        $threshold = $sloppy->configuration->failOn();
        $failed = $threshold instanceof Severity && $result->hasAtOrAbove($threshold);
        $factory = new FormatterFactory(sloppy: $sloppy, failOn: $threshold);

        $written = $this->emit(
            $factory->for($format)->format($result),
            $factory->for(OutputFormat::Console)->format($result),
            $format,
            $options,
            $sloppy,
            $output,
        );

        $this->summary(
            (new MarkdownFormatter(risk: $sloppy->risks()))->format($result),
            $options,
            $output,
        );

        $this->outputs([
            'mode' => 'scan',
            'base' => '',
            'score' => $result->score->value,
            'score-delta' => 0,
            'findings' => $result->count(),
            'new-findings' => $result->count(),
            'resolved-findings' => 0,
            'status' => $failed ? 'failed' : 'passed',
        ], $output);

        if (! $written) {
            return ExitCode::Error;
        }

        return $failed ? ExitCode::FindingsAboveThreshold : ExitCode::Success;
    }

    /**
     * A diff rendered the way this format renders things.
     *
     * The formats with a diff-aware report get it; the ones without -- SARIF,
     * Code Quality, a Rector config -- are handed the findings the changed
     * files carry now, which is the closest true statement their shape can
     * make.
     */
    private function diffReport(Sloppy $sloppy, DiffReport $report, OutputFormat $format): string
    {
        return match ($format) {
            OutputFormat::Github => (new DiffGithubFormatter)->format($report),
            OutputFormat::Json => (new DiffJsonFormatter)->format($report),
            OutputFormat::Console, OutputFormat::Markdown => (new ReviewFormatter(
                risk: $sloppy->risks(),
                markdown: $format === OutputFormat::Markdown,
            ))->format($report),
            OutputFormat::Sarif, OutputFormat::Gitlab, OutputFormat::Rector => (new FormatterFactory($sloppy))
                ->for($format)
                ->format($report->currentResult()),
        };
    }

    /**
     * Send the report where it was asked to go.
     *
     * With `--report` it goes to a file, because that is what an artifact is,
     * and the console report goes to the log so the job is still readable
     * without downloading anything.
     */
    private function emit(
        string $report,
        string $console,
        OutputFormat $format,
        CiOptions $options,
        Sloppy $sloppy,
        RunnerOutput $output,
    ): bool {
        if ($options->report === null) {
            $output->report($report, $format);

            return true;
        }

        $path = $sloppy->configuration->absolutePath($options->report);

        if (@file_put_contents($path, $report) === false) {
            $output->error(sprintf('Could not write the report to %s.', $path));

            return false;
        }

        $output->notice(sprintf('Wrote the %s report to %s.', $format->value, $options->report));
        $output->report($console, OutputFormat::Console);

        return true;
    }

    /**
     * Append the readable report to the job summary, where GitHub renders it
     * under the job rather than inside a log nobody expands.
     */
    private function summary(string $markdown, CiOptions $options, RunnerOutput $output): void
    {
        $path = $this->environment->summaryPath();

        if (! $options->summary || $path === null) {
            return;
        }

        if (@file_put_contents($path, $markdown, FILE_APPEND) === false) {
            $output->warn(sprintf('Could not write the job summary to %s.', $path));

            return;
        }

        $output->notice('Wrote the job summary.');
    }

    /**
     * Hand the numbers back to the workflow, so a later step can comment on
     * the pull request, gate a deploy, or publish a badge without re-running
     * anything.
     *
     * @param  array<string, string|int>  $values
     */
    private function outputs(array $values, RunnerOutput $output): void
    {
        $path = $this->environment->outputPath();

        if ($path === null) {
            return;
        }

        $lines = '';

        foreach ($values as $name => $value) {
            $lines .= $name.'='.$value."\n";
        }

        if (@file_put_contents($path, $lines, FILE_APPEND) === false) {
            $output->warn(sprintf('Could not write step outputs to %s.', $path));
        }
    }
}
