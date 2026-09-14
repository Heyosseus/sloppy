<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Integrations\Tooling\ProcessToolRunner;
use Heyosseus\Sloppy\Integrations\Tooling\RectorRules;
use Heyosseus\Sloppy\Integrations\Tooling\ToolDetector;
use Heyosseus\Sloppy\Integrations\Tooling\ToolResult;
use Heyosseus\Sloppy\Integrations\Tooling\ToolRunner;
use Heyosseus\Sloppy\Output\RectorFormatter;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * Hand the fixable findings to Rector, then hand the result to Pint.
 *
 * Sloppy does not rewrite code itself. Two tools in this ecosystem already do
 * it well, every Laravel project has at least one of them, and a third
 * rewriter with its own idea of formatting would fight both. What Sloppy knows
 * that they do not is *which* of their rules this project currently needs, so
 * that is what it contributes: a generated Rector config scoped to the files
 * that actually have findings, run, and then formatted back into the project's
 * own style by Pint.
 *
 * Findings with no automated fix are reported rather than passed over -- the
 * point of the command is to shorten the list, and knowing what is left is
 * half of that.
 */
final readonly class FixRunner
{
    public function __construct(private ToolRunner $tools = new ProcessToolRunner) {}

    public function run(Sloppy $sloppy, FixOptions $options, RunnerOutput $output): ExitCode
    {
        try {
            $sloppy = (new ConfigurationResolver)->resolve($sloppy, $options);

            if (! $sloppy->configuration->enabled()) {
                $output->info('Sloppy is disabled (sloppy.enabled is false).');

                return ExitCode::Success;
            }

            $before = $sloppy->analyze();
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        return $this->fix($sloppy, $before, $options, $output);
    }

    private function fix(Sloppy $sloppy, AnalysisResult $before, FixOptions $options, RunnerOutput $output): ExitCode
    {
        if ($before->isEmpty()) {
            $output->info('Nothing to fix: no findings.');

            return ExitCode::Success;
        }

        $fixable = array_values(array_filter(
            $before->findings,
            static fn (Finding $finding): bool => RectorRules::fixes($finding->ruleId),
        ));

        if ($fixable === []) {
            $this->reportRemaining($before->findings, $output);

            return ExitCode::Success;
        }

        $configPath = $sloppy->configuration->absolutePath($options->configFile);

        if (@file_put_contents($configPath, (new RectorFormatter)->format($before)) === false) {
            $output->error(sprintf('Could not write the Rector configuration to %s.', $configPath));

            return ExitCode::Error;
        }

        $output->notice(sprintf(
            '%d of %d finding(s) have an automated fix. Wrote %s.',
            count($fixable),
            $before->count(),
            $options->configFile,
        ));

        $failures = $this->runTools($sloppy, $options, $this->paths($fixable), $output);

        if (! $options->keepConfig && is_file($configPath)) {
            @unlink($configPath);
        }

        $this->reportRemaining(RectorFormatter::unfixable($before->findings), $output);
        $this->reportDelta($sloppy, $before, $options, $output);

        return $failures > 0 ? ExitCode::Error : ExitCode::Success;
    }

    /**
     * @param  list<string>  $paths
     * @return int How many tools failed.
     */
    private function runTools(Sloppy $sloppy, FixOptions $options, array $paths, RunnerOutput $output): int
    {
        $detector = new ToolDetector($sloppy->configuration->basePath);
        $basePath = $sloppy->configuration->basePath;
        $failures = 0;

        if ($options->withRector && ! $this->rector($detector, $options, $basePath, $output)) {
            $failures++;
        }

        if ($options->withPint && ! $this->pint($detector, $options, $basePath, $paths, $output)) {
            $failures++;
        }

        return $failures;
    }

    private function rector(ToolDetector $detector, FixOptions $options, string $basePath, RunnerOutput $output): bool
    {
        $binary = $detector->rector();

        if ($binary === null) {
            $output->warn('Rector is not installed. Run composer require --dev rector/rector, then run the generated config.');

            return true;
        }

        $command = [$binary, 'process', '--config='.$options->configFile, '--no-progress-bar'];

        if ($options->dryRun) {
            $command[] = '--dry-run';
        }

        return $this->report($this->tools->run('rector', $command, $basePath), $output);
    }

    /**
     * @param  list<string>  $paths
     */
    private function pint(ToolDetector $detector, FixOptions $options, string $basePath, array $paths, RunnerOutput $output): bool
    {
        $binary = $detector->pint();

        if ($binary === null) {
            $output->warn('Pint is not installed, so the rewritten files were left unformatted.');

            return true;
        }

        // Pint is pointed at the files Rector touched rather than the whole
        // project: a fix command that reformats a thousand untouched files has
        // buried its own diff.
        $command = [$binary, ...$paths];

        if ($options->dryRun) {
            $command[] = '--test';
        }

        return $this->report($this->tools->run('pint', $command, $basePath), $output);
    }

    private function report(ToolResult $result, RunnerOutput $output): bool
    {
        if ($result->successful()) {
            $output->notice(sprintf('%s finished.', $result->tool));

            return true;
        }

        $output->error(sprintf("%s exited with %d:\n%s", $result->tool, $result->exitCode, $result->tail()));

        return false;
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    private function paths(array $findings): array
    {
        $paths = [];

        foreach ($findings as $finding) {
            $paths[$finding->location->relativePath] = true;
        }

        $unique = array_keys($paths);
        sort($unique);

        return $unique;
    }

    /**
     * @param  list<Finding>  $remaining
     */
    private function reportRemaining(array $remaining, RunnerOutput $output): void
    {
        if ($remaining === []) {
            return;
        }

        $counts = [];

        foreach ($remaining as $finding) {
            $counts[$finding->ruleId] = ($counts[$finding->ruleId] ?? 0) + 1;
        }

        ksort($counts);

        $output->warn(sprintf(
            '%d finding(s) need a person: %s.',
            count($remaining),
            implode(', ', array_map(
                static fn (string $id, int $count): string => sprintf('%s x%d', $id, $count),
                array_keys($counts),
                array_values($counts),
            )),
        ));
    }

    private function reportDelta(Sloppy $sloppy, AnalysisResult $before, FixOptions $options, RunnerOutput $output): void
    {
        if ($options->dryRun) {
            $output->info('Dry run: nothing was written.');

            return;
        }

        // Not guarded: this is the second run of an analysis that already
        // succeeded with the same configuration, and the command surfaces
        // handle anything a rewriter could have broken.
        $after = $sloppy->analyze();

        $output->info(sprintf(
            'Findings %d -> %d. Score %d/100 -> %d/100.',
            $before->count(),
            $after->count(),
            $before->score->value,
            $after->score->value,
        ));
    }
}
