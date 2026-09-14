<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Baseline;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Finding;
use RuntimeException;

/**
 * Reads, writes and applies baselines.
 *
 * The point of a baseline is that adopting Sloppy on an existing codebase does
 * not mean fixing everything first: existing findings are recorded, and only
 * what appears afterwards fails the build.
 */
final readonly class BaselineManager
{
    public function create(AnalysisResult $result, ?string $generatedAt = null): Baseline
    {
        return Baseline::fromFindings(
            findings: $result->findings,
            generatedAt: $generatedAt ?? gmdate('c'),
            score: $result->score->value,
        );
    }

    public function exists(string $path): bool
    {
        return is_file($path);
    }

    /**
     * Load a baseline, or null when the project has none.
     *
     * @throws RuntimeException when the file exists but cannot be understood -- silently
     *                          treating a corrupt baseline as empty would fail a build for
     *                          reasons nobody could explain.
     */
    public function load(string $path): ?Baseline
    {
        if (! is_file($path)) {
            return null;
        }

        // Suppressed so the failure is reported in this package's words
        // rather than as a PHP warning followed by a second, vaguer error.
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Baseline file [%s] could not be read.', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'Baseline file [%s] is not valid JSON: %s. Delete it and regenerate with `php artisan sloppy:baseline`.',
                $path,
                json_last_error_msg(),
            ));
        }

        /** @var array<string, mixed> $decoded */
        return Baseline::fromArray($decoded);
    }

    public function save(Baseline $baseline, string $path): void
    {
        $encoded = json_encode($baseline->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new RuntimeException('Baseline could not be encoded as JSON.');
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Baseline directory [%s] could not be created.', $directory));
        }

        if (@file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException(sprintf('Baseline file [%s] could not be written.', $path));
        }
    }

    /**
     * Split findings into the ones the baseline already accepts and the ones
     * it does not.
     *
     * Occurrences beyond the recorded count are treated as new: if a file had
     * one swallowed exception and now has three, two of them are new.
     *
     * @param  list<Finding>  $findings
     * @return array{new: list<Finding>, baselined: list<Finding>}
     */
    public function partition(array $findings, ?Baseline $baseline): array
    {
        if (! $baseline instanceof Baseline) {
            return ['new' => $findings, 'baselined' => []];
        }

        /** @var array<string, int> $used */
        $used = [];
        $new = [];
        $baselined = [];

        foreach ($findings as $finding) {
            $id = $finding->identity();
            $seen = $used[$id] ?? 0;

            if ($seen < $baseline->allowanceFor($finding)) {
                $used[$id] = $seen + 1;
                $baselined[] = $finding;

                continue;
            }

            $new[] = $finding;
        }

        return ['new' => $new, 'baselined' => $baselined];
    }

    /**
     * Baseline entries that no longer correspond to any finding -- debt that
     * has been paid off and can be pruned.
     *
     * @param  list<Finding>  $findings
     * @return list<BaselineEntry>
     */
    public function resolved(array $findings, Baseline $baseline): array
    {
        $present = [];

        foreach ($findings as $finding) {
            $present[$finding->identity()] = true;
        }

        return array_values(array_filter(
            $baseline->entries(),
            static fn (BaselineEntry $entry): bool => ! isset($present[$entry->id]),
        ));
    }
}
