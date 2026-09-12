<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Baseline\Baseline;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * Record the current findings as accepted, so only new ones fail.
 *
 * This is how an existing codebase adopts Sloppy without a rewrite first.
 */
final readonly class BaselineRunner
{
    public function run(Sloppy $sloppy, BaselineOptions $options, RunnerOutput $output): ExitCode
    {
        try {
            $sloppy = (new ConfigurationResolver)->resolve($sloppy, $options);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        $path = $sloppy->configuration->baselinePath();
        $manager = $sloppy->baselines();

        if ($manager->exists($path) && ! $options->force) {
            $output->error(sprintf(
                '%s already exists. Re-run with --force to replace it.',
                $this->relative($sloppy, $path),
            ));

            return ExitCode::Error;
        }

        try {
            $result = $sloppy->analyze();
            $existing = $manager->exists($path) ? $manager->load($path) : null;
            $baseline = $manager->create($result);
            $manager->save($baseline, $path);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error;
        }

        $this->report($sloppy, $result->fileCount(), $baseline, $existing, $path, $output);

        return ExitCode::Success;
    }

    private function report(
        Sloppy $sloppy,
        int $files,
        Baseline $baseline,
        ?Baseline $previous,
        string $path,
        RunnerOutput $output,
    ): void {
        $output->line('');
        $output->info(sprintf(
            'Baselined %d finding(s) across %d file(s) into %s.',
            $baseline->count(),
            $files,
            $this->relative($sloppy, $path),
        ));

        if ($previous instanceof Baseline) {
            $delta = $baseline->count() - $previous->count();

            $output->line(sprintf(
                '  <fg=gray>Previous baseline held %d finding(s) (%s).</>',
                $previous->count(),
                $delta === 0 ? 'no change' : sprintf('%+d', $delta),
            ));
        }

        $output->line(sprintf(
            '  <fg=gray>Score at baseline: %s. Commit this file so CI compares against it.</>',
            $baseline->score === null ? 'unknown' : $baseline->score.'/100',
        ));
        $output->line('');
    }

    private function relative(Sloppy $sloppy, string $path): string
    {
        $base = $sloppy->configuration->basePath;
        $normalised = str_replace('\\', '/', $path);

        return str_starts_with($normalised, $base.'/')
            ? substr($normalised, strlen($base) + 1)
            : $normalised;
    }
}
