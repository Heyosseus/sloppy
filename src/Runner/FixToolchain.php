<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Integrations\Tooling\ProcessToolRunner;
use Heyosseus\Sloppy\Integrations\Tooling\ToolDetector;
use Heyosseus\Sloppy\Integrations\Tooling\ToolResult;
use Heyosseus\Sloppy\Integrations\Tooling\ToolRunner;

/**
 * Rector, then Pint: the two rewriters `sloppy fix` hands the project to.
 *
 * Which of them runs, on which files, and what counts as one of them failing
 * is decided here; what to fix and how to report it is `FixRunner`'s.
 */
final readonly class FixToolchain
{
    /** Rector's exit code for "a dry run found changes to make". */
    private const int RECTOR_CHANGES_PENDING = 2;

    public function __construct(private ToolRunner $tools = new ProcessToolRunner) {}

    /**
     * Run the tools this fix asked for, Rector first so Pint formats what it
     * rewrote.
     *
     * @param  list<string>  $rectorPaths  The files Rector was pointed at.
     * @param  list<string>  $rewritten  Files Sloppy already rewrote itself.
     * @return int How many tools failed.
     */
    public function run(string $basePath, FixOptions $options, array $rectorPaths, array $rewritten, RunnerOutput $output): int
    {
        $detector = new ToolDetector($basePath);
        $failures = 0;
        $before = $this->fingerprints($basePath, $rectorPaths);

        if ($options->withRector && ! $this->rector($detector, $options, $basePath, $output)) {
            $failures++;
        }

        if (! $options->withPint) {
            return $failures;
        }

        // Pint is pointed at the files that were actually rewritten rather
        // than every file with a finding: a fix command that restyles code it
        // did not touch has buried its own diff.
        $changed = array_values(array_unique([
            ...$rewritten,
            ...array_keys(array_diff_assoc($this->fingerprints($basePath, $rectorPaths), $before)),
        ]));
        sort($changed);

        if ($options->dryRun) {
            $output->info('Dry run: Pint was not run, because it formats only the files a real run rewrites.');

            return $failures;
        }

        if ($changed === []) {
            return $failures;
        }

        if (! $this->usesPint($basePath)) {
            $output->info('Pint was skipped: the project has no pint.json, and Pint\'s default Laravel preset would restyle a project that is not Laravel. Add a pint.json to opt in.');

            return $failures;
        }

        if (! $this->pint($detector, $basePath, $changed, $output)) {
            $failures++;
        }

        return $failures;
    }

    /**
     * Whether Pint would format this project in its own style: either the
     * project says how with a pint.json, or it is a Laravel application and
     * Pint's default preset is the style it already follows.
     */
    private function usesPint(string $basePath): bool
    {
        if (is_file($basePath.'/pint.json') || is_file($basePath.'/artisan')) {
            return true;
        }

        $composer = @file_get_contents($basePath.'/composer.json');
        $manifest = is_string($composer) ? json_decode($composer, true) : null;

        return is_array($manifest)
            && is_array($manifest['require'] ?? null)
            && array_key_exists('laravel/framework', $manifest['require']);
    }

    /**
     * A hash per file, so the files Rector rewrote can be told from the ones
     * it was pointed at and left alone.
     *
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    private function fingerprints(string $basePath, array $paths): array
    {
        $hashes = [];

        foreach ($paths as $path) {
            $hash = @hash_file('xxh128', $basePath.'/'.$path);
            $hashes[$path] = is_string($hash) ? $hash : '';
        }

        return $hashes;
    }

    private function rector(ToolDetector $detector, FixOptions $options, string $basePath, RunnerOutput $output): bool
    {
        $binary = $detector->rector();

        if ($binary === null) {
            $output->warn('Rector is not installed. Run composer require --dev rector/rector, then run the generated config.');

            return true;
        }

        $command = [$binary, 'process', '--config='.$options->configFile, '--no-progress-bar'];

        if (! $options->dryRun) {
            return $this->report($this->tools->run('rector', $command, $basePath), $output);
        }

        $command[] = '--dry-run';
        $result = $this->tools->run('rector', $command, $basePath);

        // On a dry run Rector exits 2 to say "these files would change": the
        // answer the user asked for, not a failure.
        if ($result->exitCode === self::RECTOR_CHANGES_PENDING) {
            $output->line(trim($result->output));
            $output->notice('rector would change the files above.');

            return true;
        }

        return $this->report($result, $output);
    }

    /**
     * @param  list<string>  $paths
     */
    private function pint(ToolDetector $detector, string $basePath, array $paths, RunnerOutput $output): bool
    {
        $binary = $detector->pint();

        if ($binary === null) {
            $output->warn('Pint is not installed, so the rewritten files were left unformatted.');

            return true;
        }

        return $this->report($this->tools->run('pint', [$binary, ...$paths], $basePath), $output);
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
}
