<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Baseline\Baseline;
use Heyosseus\Sloppy\Console\Commands\Concerns\ResolvesConfiguration;
use Heyosseus\Sloppy\Console\ExitCode;
use Heyosseus\Sloppy\Output\ConsoleFormatter;
use Heyosseus\Sloppy\Output\JsonFormatter;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * `php artisan sloppy` -- analyse the configured paths.
 */
final class SloppyCommand extends SloppyCommandBase
{
    use ResolvesConfiguration;

    /**
     * Shown when more than this many files are queued, so small runs and test
     * output stay clean.
     */
    private const int PROGRESS_THRESHOLD = 50;

    protected $signature = 'sloppy
        {--path=* : Analyse these paths instead of the configured ones}
        {--format=console : console or json}
        {--fail-on= : Lowest severity that fails the command, or "never"}
        {--min-confidence= : Drop findings below this confidence (0-100)}
        {--rule=* : Run only these rule IDs, e.g. --rule=SL101}
        {--explain : Include each rule\'s "why this matters" text}
        {--no-baseline : Report every finding, including baselined ones}';

    protected $description = 'Analyse the application for AI-slop code patterns';

    public function handle(Sloppy $sloppy): int
    {
        try {
            $sloppy = $this->resolveSloppy($sloppy);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        $configuration = $sloppy->configuration;

        if (! $configuration->enabled()) {
            $this->components->info('Sloppy is disabled (sloppy.enabled is false).');

            return ExitCode::Success->value;
        }

        if ($sloppy->rules()->count() === 0) {
            $this->components->error('No rules are enabled. Check sloppy.rules and any --rule filter.');

            return ExitCode::Error->value;
        }

        $files = $sloppy->fileMap();

        if ($files === []) {
            $this->components->warn(sprintf(
                'No PHP files found in: %s.',
                implode(', ', $configuration->paths()),
            ));

            return ExitCode::Success->value;
        }

        try {
            $format = $this->outputFormat();
            $result = $this->runAnalysis($sloppy, $files, $format);
            $baseline = $this->boolOption('no-baseline') ? null : $sloppy->baselines()->load($configuration->baselinePath());
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        $reported = $this->applyBaseline($sloppy, $result, $baseline);
        $threshold = $this->thresholdFor($configuration);

        $this->render($reported, $format, $threshold);

        return $this->exitCodeFor($reported, $threshold);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function runAnalysis(Sloppy $sloppy, array $files, string $format): AnalysisResult
    {
        if ($format !== 'console' || count($files) <= self::PROGRESS_THRESHOLD || $this->output->isQuiet()) {
            return $sloppy->analyze();
        }

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        $result = $sloppy->analyze(static function (string $path) use ($bar): void {
            $bar->advance();
        });

        $bar->finish();
        $this->output->newLine();

        return $result;
    }

    /**
     * Remove findings the baseline already accepts, and say how many were
     * hidden so the number is never a surprise.
     */
    private function applyBaseline(Sloppy $sloppy, AnalysisResult $result, ?Baseline $baseline): AnalysisResult
    {
        if (! $baseline instanceof Baseline) {
            return $result;
        }

        $partition = $sloppy->baselines()->partition($result->findings, $baseline);

        if ($partition['baselined'] !== []) {
            $this->components->info(sprintf(
                '%d existing finding(s) hidden by %s.',
                count($partition['baselined']),
                basename($sloppy->configuration->baselinePath()),
            ));
        }

        return $result->withFindings($partition['new'], $sloppy->scores());
    }

    private function render(AnalysisResult $result, string $format, ?Severity $threshold): void
    {
        $formatter = $format === 'json'
            ? new JsonFormatter
            : new ConsoleFormatter(explain: $this->boolOption('explain'), failOn: $threshold);

        $this->writeReport($formatter->format($result), $format);
    }

    private function exitCodeFor(AnalysisResult $result, ?Severity $threshold): int
    {
        if ($threshold instanceof Severity && $result->hasAtOrAbove($threshold)) {
            return ExitCode::FindingsAboveThreshold->value;
        }

        return ExitCode::Success->value;
    }
}
