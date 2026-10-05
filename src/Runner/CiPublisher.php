<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Sloppy;

/**
 * Everywhere a pipeline run leaves its results: the report, the job summary
 * and the step outputs a later step reads.
 */
final readonly class CiPublisher
{
    public function __construct(private CiEnvironment $environment = new CiEnvironment) {}

    /**
     * Send the report where it was asked to go.
     *
     * With `--report` it goes to a file, because that is what an artifact is,
     * and the console report goes to the log so the job is still readable
     * without downloading anything.
     */
    public function report(
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
    public function summary(string $markdown, CiOptions $options, RunnerOutput $output): void
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
    public function outputs(array $values, RunnerOutput $output): void
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
