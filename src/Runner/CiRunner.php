<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Output\DiffConsoleFormatter;
use Heyosseus\Sloppy\Output\DiffFormatterFactory;
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
 *   where the code is. A pull request is compared against where it forked
 *   from its target branch.
 * - GitLab gets a whole-project Code Quality artifact, because GitLab's own
 *   merge request widget works out which findings are new by comparing the
 *   report against the target branch's. Handing it a diff would break that.
 * - Anywhere else gets the console report a human is watching.
 * - A file that could not be parsed fails the step, because it is a file
 *   nobody checked.
 */
final readonly class CiRunner
{
    public function __construct(private CiEnvironment $environment = new CiEnvironment) {}

    public function run(Sloppy $sloppy, CiOptions $options, RunnerOutput $output): ExitCode
    {
        try {
            $sloppy = (new ConfigurationResolver)->resolve($sloppy, $options, $output);
            (new ConfigurationResolver)->assertPathsExist($sloppy, $options);
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
            $base = (new CiBaseResolver($this->environment))->resolve($sloppy, $options, $provider, $output);

            return $base === null
                ? $this->scan($sloppy, $options, $format, $output)
                : $this->diff($sloppy, $options, $format, $base, $output);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }
    }

    private function diff(Sloppy $sloppy, CiOptions $options, OutputFormat $format, string $base, RunnerOutput $output): ExitCode
    {
        $report = $sloppy->diff($base);

        if ((new ConfigurationResolver)->nothingParsed($sloppy, $report->errors)) {
            $output->error('None of the project\'s PHP files could be parsed, so there is no score to report.');

            return ExitCode::Error;
        }

        $threshold = $sloppy->configuration->failOn();
        $failed = $threshold instanceof Severity && $report->newAtOrAbove($threshold) !== [];

        // The formats with a diff-aware report get it; the ones without --
        // SARIF, Code Quality, a Rector config -- are handed the findings the
        // changed files carry now, which is the closest true statement their
        // shape can make.
        $written = $this->publisher()->report(
            (new DiffFormatterFactory(
                sloppy: $sloppy,
                explainRisk: $options->explainRisk,
                failOn: $threshold,
                review: true,
                wholeFiles: true,
            ))->format($report, $format),
            (new DiffConsoleFormatter(failOn: $threshold))->format($report),
            $format,
            $options,
            $sloppy,
            $output,
        );

        $this->publisher()->summary(
            (new ReviewFormatter(risk: $sloppy->risks(), explainRisk: $options->explainRisk, markdown: true))->format($report),
            $options,
            $output,
        );

        $this->publisher()->outputs([
            'mode' => 'diff',
            'base' => $base,
            'score' => $report->currentScore->value,
            'score-delta' => $report->scoreDelta(),
            'findings' => count($report->new) + count($report->existing),
            'new-findings' => count($report->new),
            'resolved-findings' => count($report->resolved),
            'status' => $failed ? 'failed' : 'passed',
        ], $output);

        if (! $written || ! $this->parsed($report->errors, $options, $output)) {
            return ExitCode::Error;
        }

        return $failed ? ExitCode::FindingsAboveThreshold : ExitCode::Success;
    }

    private function scan(Sloppy $sloppy, CiOptions $options, OutputFormat $format, RunnerOutput $output): ExitCode
    {
        if ($sloppy->fileMap() === []) {
            $output->error(sprintf('No PHP files found in: %s.', implode(', ', $sloppy->configuration->paths())));

            return ExitCode::Error;
        }

        $analysed = $sloppy->analyze();

        if ((new ConfigurationResolver)->nothingParsed($sloppy, $analysed->errors)) {
            $output->error('None of the project\'s PHP files could be parsed, so there is no score to report.');

            return ExitCode::Error;
        }

        $result = (new BaselineFilter)->apply($sloppy, $analysed, $output, ! $options->noBaseline);
        $threshold = $sloppy->configuration->failOn();
        $failed = $threshold instanceof Severity && $result->hasAtOrAbove($threshold);
        $factory = new FormatterFactory(sloppy: $sloppy, explainRisk: $options->explainRisk, failOn: $threshold);

        $written = $this->publisher()->report(
            $factory->for($format)->format($result),
            $factory->for(OutputFormat::Console)->format($result),
            $format,
            $options,
            $sloppy,
            $output,
        );

        $this->publisher()->summary(
            (new MarkdownFormatter(risk: $sloppy->risks(), explainRisk: $options->explainRisk))->format($result),
            $options,
            $output,
        );

        $this->publisher()->outputs([
            'mode' => 'scan',
            'base' => '',
            'score' => $result->score->value,
            'score-delta' => 0,
            'findings' => $result->count(),
            'new-findings' => $result->count(),
            'resolved-findings' => 0,
            'status' => $failed ? 'failed' : 'passed',
        ], $output);

        if (! $written || ! $this->parsed($result->errors, $options, $output)) {
            return ExitCode::Error;
        }

        return $failed ? ExitCode::FindingsAboveThreshold : ExitCode::Success;
    }

    /**
     * Whether the run read everything it was meant to.
     *
     * A file the parser could not read is a file nobody checked, and in a
     * pipeline that is a failure of the step rather than a footnote under a
     * green check -- unless the project has said, with
     * `--allow-parse-errors`, that it knows and accepts it.
     *
     * @param  array<string, string>  $errors
     */
    private function parsed(array $errors, CiOptions $options, RunnerOutput $output): bool
    {
        if ($errors === [] || $options->allowParseErrors) {
            return true;
        }

        $first = array_key_first($errors);

        $output->error(sprintf(
            '%d file(s) could not be analysed, starting with %s: %s (pass --allow-parse-errors to accept this).',
            count($errors),
            $first,
            $errors[$first] ?? '',
        ));

        return false;
    }

    private function publisher(): CiPublisher
    {
        return new CiPublisher($this->environment);
    }
}
