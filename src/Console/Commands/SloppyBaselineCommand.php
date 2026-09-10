<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Baseline\Baseline;
use Heyosseus\Sloppy\Console\Commands\Concerns\ResolvesConfiguration;
use Heyosseus\Sloppy\Console\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * `php artisan sloppy:baseline` -- accept the current findings so that only
 * new ones fail the build.
 *
 * This is how an existing codebase adopts Sloppy without a rewrite first.
 */
final class SloppyBaselineCommand extends SloppyCommandBase
{
    use ResolvesConfiguration;

    protected $signature = 'sloppy:baseline
        {--path=* : Analyse these paths instead of the configured ones}
        {--min-confidence= : Only baseline findings at or above this confidence (0-100)}
        {--rule=* : Baseline only these rule IDs}
        {--force : Overwrite an existing baseline}';

    protected $description = 'Record the current findings as accepted, so only new ones fail';

    public function handle(Sloppy $sloppy): int
    {
        try {
            $sloppy = $this->resolveSloppy($sloppy);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        $path = $sloppy->configuration->baselinePath();
        $manager = $sloppy->baselines();

        if ($manager->exists($path) && ! $this->boolOption('force')) {
            $this->components->error(sprintf(
                '%s already exists. Re-run with --force to replace it.',
                $this->relative($sloppy, $path),
            ));

            return ExitCode::Error->value;
        }

        try {
            $result = $sloppy->analyze();
            $existing = $manager->exists($path) ? $manager->load($path) : null;
            $baseline = $manager->create($result);
            $manager->save($baseline, $path);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        $this->report($sloppy, $result->fileCount(), $baseline, $existing, $path);

        return ExitCode::Success->value;
    }

    private function report(Sloppy $sloppy, int $files, Baseline $baseline, ?Baseline $previous, string $path): void
    {
        $this->output->writeln('');
        $this->components->info(sprintf(
            'Baselined %d finding(s) across %d file(s) into %s.',
            $baseline->count(),
            $files,
            $this->relative($sloppy, $path),
        ));

        if ($previous instanceof Baseline) {
            $delta = $baseline->count() - $previous->count();

            $this->output->writeln(sprintf(
                '  <fg=gray>Previous baseline held %d finding(s) (%s).</>',
                $previous->count(),
                $delta === 0 ? 'no change' : sprintf('%+d', $delta),
            ));
        }

        $this->output->writeln(sprintf(
            '  <fg=gray>Score at baseline: %s. Commit this file so CI compares against it.</>',
            $baseline->score === null ? 'unknown' : $baseline->score.'/100',
        ));
        $this->output->writeln('');
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
