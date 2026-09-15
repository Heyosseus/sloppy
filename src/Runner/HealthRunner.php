<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Integrations\HealthReporter;
use Heyosseus\Sloppy\Integrations\HealthSnapshot;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * The score, the breakdown and what to read first -- and the same JSON the
 * dashboard surfaces read.
 *
 * `sloppy` reports findings for a person about to fix them. This reports the
 * shape of the codebase for something that has to draw it: a status line, a
 * desktop menu bar, a Filament widget, an agent orienting itself. It shares
 * their cache, so running it warms the widget and the widget warms it.
 */
final readonly class HealthRunner
{
    public function run(Sloppy $sloppy, HealthOptions $options, RunnerOutput $output): ExitCode
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

        try {
            // A different `--top` than the cached snapshot was built with
            // cannot be served from it, so asking for one re-analyses.
            $snapshot = (new HealthReporter($sloppy, $options->top))->current(
                fresh: $options->fresh || $options->top !== null,
            );
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        $output->report(
            $this->render($snapshot, $options, $this->coverageLine($sloppy)),
            $options->json ? OutputFormat::Json : OutputFormat::Console,
        );

        return ExitCode::Success;
    }

    /**
     * Which coverage report is in play, and how old it is.
     *
     * Health is the command people run to check their setup, so this is where
     * a missing report belongs. The age is reported and never acted on: a
     * staleness heuristic would be a number that cannot show its arithmetic.
     */
    private function coverageLine(Sloppy $sloppy): string
    {
        $coverage = $sloppy->coverage();
        $path = $coverage->sourcePath();

        if ($path === null) {
            return 'No coverage report found, so findings in untested files are not ranked higher.';
        }

        $generated = $coverage->generatedAt();

        return sprintf(
            'Coverage read from %s%s.',
            $path,
            $generated === null ? '' : sprintf(', written %s', date('Y-m-d H:i', $generated)),
        );
    }

    private function render(HealthSnapshot $snapshot, HealthOptions $options, string $coverageLine): string
    {
        if ($options->json) {
            $encoded = json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return ($encoded === false ? '{}' : $encoded)."\n";
        }

        $lines = [
            sprintf('<options=bold>%d/100</> %s', $snapshot->score, $snapshot->label()),
            '',
            sprintf(
                '%d finding(s) across %d file(s), %s lines analysed.',
                $snapshot->findings,
                $snapshot->files,
                number_format($snapshot->lines),
            ),
        ];

        foreach ($snapshot->byCategory as $category => $count) {
            $lines[] = sprintf('  %-14s %d', $category, $count);
        }

        $lines[] = '';
        $lines[] = $coverageLine;

        if ($snapshot->top !== []) {
            $lines[] = '';
            $lines[] = 'Read first:';

            foreach ($snapshot->top as $finding) {
                $lines[] = sprintf(
                    '  %s %s  %s:%d  risk %.1f',
                    $finding['rule'],
                    $finding['name'],
                    $finding['file'],
                    $finding['line'],
                    $finding['risk'],
                );
            }
        }

        return implode(PHP_EOL, [...$lines, '']).PHP_EOL;
    }
}
