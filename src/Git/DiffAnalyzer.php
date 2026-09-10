<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

use Heyosseus\Sloppy\Analysis\Analyzer;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;
use Heyosseus\Sloppy\Support\FileFinder;

/**
 * Compares the findings in changed files against the same files at another
 * revision.
 *
 * Both sides are analysed with the *whole project* in the index, but rules only
 * run on the changed files. That matters twice over: cross-file rules such as
 * abstraction inflation need to see classes the diff did not touch, and running
 * rules on unchanged files would be wasted work.
 *
 * Matching is by finding identity -- rule, file and fingerprint -- never by line
 * number, so moving code down a file does not invent new findings.
 */
final readonly class DiffAnalyzer
{
    public function __construct(
        private Git $git,
        private Analyzer $analyzer,
        private FileFinder $finder,
        private ScoreCalculator $calculator,
    ) {}

    public function compare(string $base): DiffReport
    {
        $projectFiles = $this->projectSources();

        $changed = $this->git->withHunks($base, array_values(array_filter(
            $this->git->changedFiles($base),
            fn (ChangedFile $file): bool => $file->isAnalysable()
                && ! $this->finder->isExcluded($file->relativePath)
                && isset($projectFiles[$file->relativePath]),
        )));

        // Nothing analysable changed, so both trees are the same tree. Parsing
        // the project twice to prove it would be the slowest way to say so.
        if ($changed === []) {
            return $this->unchanged($base);
        }

        $changedPaths = array_map(static fn (ChangedFile $file): string => $file->relativePath, $changed);

        $current = $this->analyzer->analyzeSources($projectFiles, $changedPaths);

        // The base tree is today's tree with the changed files rewound. Files
        // the diff did not touch are byte-identical at both revisions, so
        // reading them from git again would be pointless work.
        $baseSources = $projectFiles;
        $basePaths = [];
        $wanted = [];

        foreach ($changed as $file) {
            if ($file->existedBefore()) {
                $wanted[$file->relativePath] = $file->previousPath ?? $file->relativePath;
            }
        }

        $previousContents = $this->git->showFiles($base, array_values($wanted));

        foreach ($changed as $file) {
            $contents = isset($wanted[$file->relativePath])
                ? $previousContents[$wanted[$file->relativePath]] ?? null
                : null;

            if ($contents === null) {
                unset($baseSources[$file->relativePath]);

                continue;
            }

            $baseSources[$file->relativePath] = $contents;
            $basePaths[] = $file->relativePath;
        }

        $previous = $this->analyzer->analyzeSources($baseSources, $basePaths);

        $partition = $this->partition($current->findings, $previous->findings);

        return new DiffReport(
            base: $base,
            changedFiles: $changed,
            new: $partition['new'],
            existing: $partition['existing'],
            resolved: $partition['resolved'],
            currentScore: $this->calculator->calculate($current->findings, $current->analyzedLines),
            baseScore: $this->calculator->calculate($previous->findings, $previous->analyzedLines),
            errors: [...$previous->errors, ...$current->errors],
        );
    }

    /**
     * The report for a comparison with no analysable changes in it.
     */
    private function unchanged(string $base): DiffReport
    {
        $score = $this->calculator->calculate([], 0);

        return new DiffReport(
            base: $base,
            changedFiles: [],
            new: [],
            existing: [],
            resolved: [],
            currentScore: $score,
            baseScore: $score,
            errors: [],
        );
    }

    /**
     * Every analysable file in the project, keyed by relative path.
     *
     * @return array<string, string>
     */
    private function projectSources(): array
    {
        $sources = [];

        foreach ($this->finder->find() as $absolute) {
            $contents = file_get_contents($absolute);

            if ($contents !== false) {
                $sources[$this->finder->relative($absolute)] = $contents;
            }
        }

        return $sources;
    }

    /**
     * @param  list<Finding>  $current
     * @param  list<Finding>  $previous
     * @return array{new: list<Finding>, existing: list<Finding>, resolved: list<Finding>}
     */
    private function partition(array $current, array $previous): array
    {
        $before = $this->countByIdentity($previous);
        $after = $this->countByIdentity($current);

        $new = [];
        $existing = [];
        $seen = [];

        foreach ($current as $finding) {
            $id = $finding->identity();
            $index = $seen[$id] = ($seen[$id] ?? 0) + 1;

            // A finding that appeared once before and three times now is two
            // new findings, not zero.
            if ($index <= ($before[$id] ?? 0)) {
                $existing[] = $finding;

                continue;
            }

            $new[] = $finding;
        }

        $resolvedSeen = [];
        $resolved = [];

        foreach ($previous as $finding) {
            $id = $finding->identity();
            $index = $resolvedSeen[$id] = ($resolvedSeen[$id] ?? 0) + 1;

            if ($index > ($after[$id] ?? 0)) {
                $resolved[] = $finding;
            }
        }

        return ['new' => $new, 'existing' => $existing, 'resolved' => $resolved];
    }

    /**
     * @param  list<Finding>  $findings
     * @return array<string, int>
     */
    private function countByIdentity(array $findings): array
    {
        $counts = [];

        foreach ($findings as $finding) {
            $id = $finding->identity();
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return $counts;
    }
}
