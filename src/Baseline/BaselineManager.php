<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Baseline;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Output\JsonEncoder;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;
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
        $encoded = (new JsonEncoder)->encode($baseline->toArray());

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
     * An entry's identity includes its file, so a file that moved -- even with
     * `git mv` -- would otherwise turn every finding it carries back into a
     * new one. A finding with no entry of its own is therefore matched, second,
     * against an entry for the same rule and fingerprint in a file that is no
     * longer there, using up that entry's count so a moved finding cannot be
     * accepted twice.
     *
     * @param  list<Finding>  $findings
     * @param  list<string>|null  $presentFiles  Files the run covered; an entry for one of these
     *                                           still has its own file and is never borrowed.
     * @return array{new: list<Finding>, baselined: list<Finding>}
     */
    public function partition(array $findings, ?Baseline $baseline, ?array $presentFiles = null): array
    {
        if (! $baseline instanceof Baseline) {
            return ['new' => $findings, 'baselined' => []];
        }

        $accepted = $this->match($findings, $baseline, $presentFiles)['accepted'];
        $new = [];
        $baselined = [];

        foreach ($findings as $index => $finding) {
            if (isset($accepted[$index])) {
                $baselined[] = $finding;

                continue;
            }

            $new[] = $finding;
        }

        return ['new' => $new, 'baselined' => $baselined];
    }

    /**
     * Which findings the baseline accepts, and how much of each entry they
     * used: exact identities first, then moved files.
     *
     * @param  list<Finding>  $findings
     * @param  list<string>|null  $presentFiles
     * @return array{accepted: array<int, true>, used: array<string, int>}
     */
    private function match(array $findings, Baseline $baseline, ?array $presentFiles): array
    {
        /** @var array<string, int> $used */
        $used = [];
        $accepted = [];
        $unmatched = [];

        foreach ($findings as $index => $finding) {
            $id = $finding->identity();
            $seen = $used[$id] ?? 0;

            if ($seen < $baseline->allowanceFor($finding)) {
                $used[$id] = $seen + 1;
                $accepted[$index] = true;

                continue;
            }

            $unmatched[] = $index;
        }

        if ($unmatched === []) {
            return ['accepted' => $accepted, 'used' => $used];
        }

        $present = $presentFiles === null ? [] : array_fill_keys($presentFiles, true);
        $moved = [];

        foreach ($baseline->entries() as $entry) {
            if (! isset($present[$entry->file])) {
                $moved[$entry->ruleId."\0".$entry->fingerprint][] = $entry;
            }
        }

        foreach ($unmatched as $index) {
            $finding = $findings[$index];

            foreach ($moved[$finding->ruleId."\0".$finding->fingerprint] ?? [] as $entry) {
                $seen = $used[$entry->id] ?? 0;

                if ($seen < $entry->count) {
                    $used[$entry->id] = $seen + 1;
                    $accepted[$index] = true;

                    break;
                }
            }
        }

        return ['accepted' => $accepted, 'used' => $used];
    }

    /**
     * The same result without the findings the baseline at `$path` accepts;
     * the result itself when there is no baseline there.
     */
    public function apply(AnalysisResult $result, string $path, ScoreCalculator $scores): AnalysisResult
    {
        $baseline = $this->load($path);

        if (! $baseline instanceof Baseline) {
            return $result;
        }

        return $result->withFindings($this->partition($result->findings, $baseline, $result->analyzedFiles)['new'], $scores);
    }

    /**
     * Baseline entries that no longer correspond to any finding -- debt that
     * has been paid off and can be pruned. An entry a moved file's finding
     * still answers to is not paid off.
     *
     * @param  list<Finding>  $findings
     * @param  list<string>|null  $presentFiles
     * @return list<BaselineEntry>
     */
    public function resolved(array $findings, Baseline $baseline, ?array $presentFiles = null): array
    {
        $used = $this->match($findings, $baseline, $presentFiles)['used'];

        return array_values(array_filter(
            $baseline->entries(),
            static fn (BaselineEntry $entry): bool => ! isset($used[$entry->id]),
        ));
    }
}
