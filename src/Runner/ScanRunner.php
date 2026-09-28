<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Output\FormatterFactory;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * Analyse the configured paths and report what was found.
 *
 * This is the whole of `sloppy`, independent of which console framework asked
 * for it. Both surfaces do nothing but read options and pick an output adapter.
 */
final readonly class ScanRunner
{
    /**
     * Shown when more than this many files are queued, so small runs and test
     * output stay clean.
     */
    private const int PROGRESS_THRESHOLD = 50;

    public function run(Sloppy $sloppy, ScanOptions $options, RunnerOutput $output): ExitCode
    {
        try {
            $sloppy = (new ConfigurationResolver)->resolve($sloppy, $options);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        $configuration = $sloppy->configuration;

        if (! $configuration->enabled()) {
            $output->info('Sloppy is disabled (sloppy.enabled is false).');

            return ExitCode::Success;
        }

        if ($sloppy->rules()->count() === 0) {
            return $this->reportNoRulesEnabled($sloppy, $output);
        }

        $files = $sloppy->fileMap();

        if ($files === []) {
            $output->warn(sprintf('No PHP files found in: %s.', implode(', ', $configuration->paths())));

            return ExitCode::Success;
        }

        try {
            $result = $this->analyse($sloppy, count($files), $options, $output);
            $reported = (new BaselineFilter)->apply($sloppy, $result, $output, ! $options->noBaseline);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        $threshold = $configuration->failOn();
        $hasBaseline = $sloppy->baselines()->exists($configuration->baselinePath());

        $output->report($this->render($reported, $options, $threshold, $sloppy, $hasBaseline), $options->format);

        if (! $hasBaseline && $this->triaged($reported, $options) && $this->offerBaseline($sloppy, $result, $output)) {
            return ExitCode::Success;
        }

        return $threshold instanceof Severity && $reported->hasAtOrAbove($threshold)
            ? ExitCode::FindingsAboveThreshold
            : ExitCode::Success;
    }

    private function triaged(AnalysisResult $result, ScanOptions $options): bool
    {
        return $options->format === OutputFormat::Console
            && ! $options->listsEverything()
            && $result->count() > $options->top;
    }

    /**
     * A project meeting Sloppy for the first time has years of findings and no
     * baseline, and the kindest thing to do with those is usually to accept
     * them and watch only what comes next. So a person at a terminal is asked,
     * once the report is on screen, whether to do exactly that. Nobody else is:
     * a pipeline, a pipe or an agent gets the report and the hint in it.
     *
     * @return bool Whether the baseline was written.
     */
    private function offerBaseline(Sloppy $sloppy, AnalysisResult $result, RunnerOutput $output): bool
    {
        $path = $sloppy->configuration->baselinePath();

        if (! $output->confirm(sprintf(
            'Accept these %d findings as a baseline (%s), so scans report only new ones from now on?',
            $result->count(),
            basename($path),
        ))) {
            return false;
        }

        $sloppy->baselines()->save($sloppy->baselines()->create($result), $path);

        $output->info(sprintf(
            'Baselined %d finding(s) into %s. Commit it, and every scan from here reports only what is new.',
            $result->count(),
            basename($path),
        ));

        return true;
    }

    /**
     * Since Phase 1, "zero rules" can mean the project's own rules were all
     * gated by a missing framework rather than misconfiguration, and the
     * generic advice sends a non-Laravel user chasing a filter and a config
     * key that are both already correct.
     */
    private function reportNoRulesEnabled(Sloppy $sloppy, RunnerOutput $output): ExitCode
    {
        $skipped = $sloppy->rules()->skipped();

        $output->error($skipped === []
            ? 'No rules are enabled. Check sloppy.rules and any --rule filter.'
            : sprintf(
                'No rules are enabled: %d rule(s) were skipped for a missing framework (sloppy.framework: %s). '
                .'Check sloppy.rules and any --rule filter.',
                count($skipped),
                $sloppy->configuration->framework(),
            ));

        return ExitCode::Error;
    }

    private function analyse(Sloppy $sloppy, int $fileCount, ScanOptions $options, RunnerOutput $output): AnalysisResult
    {
        if ($options->format->isMachineReadable() || $fileCount <= self::PROGRESS_THRESHOLD || $output->isQuiet()) {
            return $sloppy->analyze();
        }

        $output->startProgress($fileCount);

        $result = $sloppy->analyze(static function (string $path) use ($output): void {
            $output->advanceProgress();
        });

        $output->finishProgress();
        $output->line('');

        return $result;
    }

    private function render(AnalysisResult $result, ScanOptions $options, ?Severity $threshold, Sloppy $sloppy, bool $hasBaseline): string
    {
        $factory = new FormatterFactory(
            sloppy: $sloppy,
            explain: $options->explain,
            explainRisk: $options->explainRisk,
            failOn: $threshold,
            all: $options->listsEverything(),
            top: $options->top,
            surface: $options->surface,
            hasBaseline: $hasBaseline,
        );

        return $factory->for($options->format)->format($result);
    }
}
