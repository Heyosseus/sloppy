<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use Heyosseus\Sloppy\Scoring\Score;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

/**
 * The outcome of one analysis run.
 *
 * Findings come back in a fixed order -- severity, then file, then line, then
 * rule -- so two runs over the same code produce byte-identical reports.
 */
final readonly class AnalysisResult
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $analyzedFiles  Relative paths, sorted.
     * @param  array<string, string>  $errors  Keyed by what failed: a relative path for a parse error, or "SL101 in app/Foo.php" for a rule that threw.
     * @param  list<string>  $skippedRules  IDs left out because the analysed project does not use their framework.
     */
    public function __construct(
        public array $findings,
        public array $analyzedFiles,
        public int $analyzedLines,
        public Score $score,
        public array $errors = [],
        public array $skippedRules = [],
    ) {}

    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $analyzedFiles
     * @param  array<string, string>  $errors
     * @param  list<string>  $skippedRules
     */
    public static function create(
        array $findings,
        array $analyzedFiles,
        int $analyzedLines,
        ScoreCalculator $calculator,
        array $errors = [],
        array $skippedRules = [],
    ): self {
        $sorted = self::sort($findings);

        return new self(
            findings: $sorted,
            analyzedFiles: $analyzedFiles,
            analyzedLines: $analyzedLines,
            score: $calculator->calculate($sorted, $analyzedLines),
            errors: $errors,
            skippedRules: $skippedRules,
        );
    }

    /**
     * Same run, different finding set -- used when a baseline or a severity
     * threshold removes findings and the score has to follow.
     *
     * @param  list<Finding>  $findings
     */
    public function withFindings(array $findings, ScoreCalculator $calculator): self
    {
        return self::create(
            findings: $findings,
            analyzedFiles: $this->analyzedFiles,
            analyzedLines: $this->analyzedLines,
            calculator: $calculator,
            errors: $this->errors,
            skippedRules: $this->skippedRules,
        );
    }

    public function count(): int
    {
        return count($this->findings);
    }

    public function isEmpty(): bool
    {
        return $this->findings === [];
    }

    public function fileCount(): int
    {
        return count($this->analyzedFiles);
    }

    /**
     * @return array<string, int>
     */
    public function countsBySeverity(): array
    {
        $counts = [];

        foreach (Severity::cases() as $severity) {
            $counts[$severity->value] = 0;
        }

        foreach ($this->findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * @return array<string, list<Finding>>
     */
    public function groupedByFile(): array
    {
        $grouped = [];

        foreach ($this->findings as $finding) {
            $grouped[$finding->location->relativePath][] = $finding;
        }

        return $grouped;
    }

    /**
     * @return list<Finding>
     */
    public function atOrAbove(Severity $threshold): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $finding): bool => $finding->severity->isAtLeast($threshold),
        ));
    }

    public function hasAtOrAbove(Severity $threshold): bool
    {
        return $this->atOrAbove($threshold) !== [];
    }

    /**
     * Canonical finding order, so reports and JSON diffs are stable.
     *
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    public static function sort(array $findings): array
    {
        usort($findings, static fn (Finding $a, Finding $b): int => [$a->severity->rank(), $a->location->relativePath, $a->location->line, $a->ruleId, $a->fingerprint]
            <=> [$b->severity->rank(), $b->location->relativePath, $b->location->line, $b->ruleId, $b->fingerprint]);

        return $findings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score->toArray(),
            'summary' => [
                'files' => $this->fileCount(),
                'lines' => $this->analyzedLines,
                'findings' => $this->count(),
                'by_severity' => $this->countsBySeverity(),
            ],
            'findings' => array_map(
                static fn (Finding $finding): array => $finding->toArray(),
                $this->findings,
            ),
            'errors' => $this->errors,
            'rules_skipped' => $this->skippedRules,
        ];
    }
}
