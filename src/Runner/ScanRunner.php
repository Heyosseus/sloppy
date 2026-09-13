<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Baseline\Baseline;
use Heyosseus\Sloppy\Contracts\Formatter;
use Heyosseus\Sloppy\Output\ConsoleFormatter;
use Heyosseus\Sloppy\Output\GithubFormatter;
use Heyosseus\Sloppy\Output\JsonFormatter;
use Heyosseus\Sloppy\Output\MarkdownFormatter;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Output\SarifFormatter;
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
            $baseline = $options->noBaseline
                ? null
                : $sloppy->baselines()->load($configuration->baselinePath());
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        $reported = $this->applyBaseline($sloppy, $result, $baseline, $output);
        $threshold = $configuration->failOn();

        $output->report($this->render($reported, $options, $threshold, $sloppy), $options->format);

        return $threshold instanceof Severity && $reported->hasAtOrAbove($threshold)
            ? ExitCode::FindingsAboveThreshold
            : ExitCode::Success;
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

    /**
     * Remove findings the baseline already accepts, and say how many were
     * hidden so the number is never a surprise.
     */
    private function applyBaseline(
        Sloppy $sloppy,
        AnalysisResult $result,
        ?Baseline $baseline,
        RunnerOutput $output,
    ): AnalysisResult {
        if (! $baseline instanceof Baseline) {
            return $result;
        }

        $partition = $sloppy->baselines()->partition($result->findings, $baseline);

        if ($partition['baselined'] !== []) {
            $output->notice(sprintf(
                '%d existing finding(s) hidden by %s.',
                count($partition['baselined']),
                basename($sloppy->configuration->baselinePath()),
            ));
        }

        return $result->withFindings($partition['new'], $sloppy->scores());
    }

    private function render(AnalysisResult $result, ScanOptions $options, ?Severity $threshold, Sloppy $sloppy): string
    {
        return $this->formatter($options, $threshold, $sloppy)->format($result);
    }

    private function formatter(ScanOptions $options, ?Severity $threshold, Sloppy $sloppy): Formatter
    {
        $risk = $sloppy->risks();

        return match ($options->format) {
            OutputFormat::Json => new JsonFormatter(explainRisk: $options->explainRisk, risk: $risk),
            OutputFormat::Sarif => new SarifFormatter(Sloppy::VERSION),
            OutputFormat::Github => new GithubFormatter,
            OutputFormat::Markdown => new MarkdownFormatter(risk: $risk, explainRisk: $options->explainRisk),
            OutputFormat::Console => new ConsoleFormatter(
                explain: $options->explain,
                failOn: $threshold,
                explainRisk: $options->explainRisk,
                risk: $risk,
            ),
        };
    }
}
