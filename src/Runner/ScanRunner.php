<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\ComposerJson;
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
            $sloppy = (new ConfigurationResolver)->resolve($sloppy, $options, $output);
            (new ConfigurationResolver)->assertPathsExist($sloppy, $options);
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
            return $this->reportNothingToAnalyse($sloppy, $output);
        }

        $threshold = $configuration->failOn();

        try {
            $result = $this->analyse($sloppy, count($files), $options, $output);

            if ((new ConfigurationResolver)->nothingParsed($sloppy, $result->errors)) {
                $first = (string) array_key_first($result->errors);

                $output->error(sprintf(
                    'None of the %d PHP file(s) could be parsed, so there is no score to report. First error: %s: %s',
                    count($files),
                    $first,
                    $result->errors[$first] ?? '',
                ));

                return ExitCode::Error;
            }

            $reported = (new BaselineFilter)->apply($sloppy, $result, $output, ! $options->noBaseline);
            $hasBaseline = $sloppy->baselines()->exists($configuration->baselinePath());
            $rendered = $this->render($reported, $options, $threshold, $sloppy, $hasBaseline);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        $output->report($rendered, $options->format);

        if ($options->format === OutputFormat::Console && $output->canAsk() && $this->wantsPhpstanExtension($sloppy)) {
            $output->notice('Already on PHPStan? composer require --dev heyosseus/phpstan-sloppy reports these in your PHPStan run.');
        }

        if (! $hasBaseline && $this->triaged($reported, $options) && $this->offerBaseline($sloppy, $result, $output)) {
            return ExitCode::Success;
        }

        return $threshold instanceof Severity && $reported->hasAtOrAbove($threshold)
            ? ExitCode::FindingsAboveThreshold
            : ExitCode::Success;
    }

    /**
     * Paths that exist but hold no PHP are a project with nothing to say yet,
     * which is a warning. Paths none of which exist are a mistake -- a typo, a
     * wrong working directory -- and passing would be a green check that
     * checked nothing.
     */
    private function reportNothingToAnalyse(Sloppy $sloppy, RunnerOutput $output): ExitCode
    {
        $configuration = $sloppy->configuration;

        foreach ($configuration->paths() as $path) {
            if (file_exists($configuration->absolutePath($path))) {
                $output->warn(sprintf('No PHP files found in: %s.', implode(', ', $configuration->paths())));

                return ExitCode::Success;
            }
        }

        $output->error(sprintf(
            'None of the configured paths exist: %s (in %s).',
            implode(', ', $configuration->paths()),
            $configuration->basePath,
        ));

        return ExitCode::Error;
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
     * A project that already runs PHPStan can have these findings in that run
     * instead of adding a step. Said only to a person at a terminal, as the
     * baseline offer is: a pipeline or an agent has no use for advertising.
     */
    private function wantsPhpstanExtension(Sloppy $sloppy): bool
    {
        $composer = new ComposerJson($sloppy->configuration->basePath);

        return ! $composer->requires('heyosseus/phpstan-sloppy')
            && ($composer->requires('phpstan/phpstan') || $composer->requires('larastan/larastan'));
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
