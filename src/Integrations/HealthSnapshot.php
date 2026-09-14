<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Integrations;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Scoring\RiskCalculator;
use Heyosseus\Sloppy\Scoring\ScoreBand;

/**
 * A project's code health, small enough to cache and to render anywhere.
 *
 * An {@see AnalysisResult} carries every finding and the detail behind it; a
 * dashboard widget, a desktop menu bar and a status command want the same
 * dozen numbers instead. This is that dozen, in a shape that survives a JSON
 * round trip -- so a surface that redraws often never has to run an analysis
 * to show one.
 */
final readonly class HealthSnapshot
{
    /**
     * @param  array<string, int>  $bySeverity  Finding count keyed by severity value.
     * @param  array<string, int>  $byCategory  Finding count keyed by category value, biggest first.
     * @param  list<array{rule: string, name: string, severity: string, file: string, line: int, message: string, risk: float}>  $top  What is worth reading first.
     * @param  list<string>  $skippedRules  Rule IDs left out for a missing framework.
     */
    public function __construct(
        public int $score,
        public ScoreBand $band,
        public int $findings,
        public int $files,
        public int $lines,
        public array $bySeverity,
        public array $byCategory,
        public array $top,
        public array $skippedRules,
        public int $generatedAt,
    ) {}

    public static function from(
        AnalysisResult $result,
        RiskCalculator $risk = new RiskCalculator,
        int $top = 5,
        ?int $generatedAt = null,
    ): self {
        return new self(
            score: $result->score->value,
            band: $result->score->band,
            findings: $result->count(),
            files: $result->fileCount(),
            lines: $result->analyzedLines,
            bySeverity: $result->countsBySeverity(),
            byCategory: self::categories($result),
            top: self::ranked($result, $risk, $top),
            skippedRules: $result->skippedRules,
            generatedAt: $generatedAt ?? time(),
        );
    }

    /**
     * Rebuild a snapshot written by {@see toArray()}.
     *
     * Every field falls back to its empty value, because the input is a file
     * on disk: one truncated by a crash, hand-edited, or written by an older
     * release must degrade to "nothing known yet" on a dashboard rather than
     * take the dashboard down with it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $band = $data['band'] ?? null;

        return new self(
            score: self::intFrom($data, 'score', 100),
            band: (is_string($band) ? ScoreBand::tryFrom($band) : null) ?? ScoreBand::Clean,
            findings: self::intFrom($data, 'findings', 0),
            files: self::intFrom($data, 'files', 0),
            lines: self::intFrom($data, 'lines', 0),
            bySeverity: self::countsFrom($data, 'by_severity'),
            byCategory: self::countsFrom($data, 'by_category'),
            top: self::topFrom($data),
            skippedRules: self::stringsFrom($data, 'rules_skipped'),
            generatedAt: self::intFrom($data, 'generated_at', 0),
        );
    }

    public function label(): string
    {
        return $this->band->label();
    }

    public function isClean(): bool
    {
        return $this->findings === 0;
    }

    /**
     * How many findings sit at or above a severity, for a surface with room
     * for one number rather than the whole breakdown.
     */
    public function countAtOrAbove(Severity $threshold): int
    {
        $count = 0;

        foreach ($this->bySeverity as $severity => $amount) {
            $parsed = Severity::tryFrom($severity);

            if ($parsed instanceof Severity && $parsed->isAtLeast($threshold)) {
                $count += $amount;
            }
        }

        return $count;
    }

    /**
     * One line a menu bar, a status line or a notification can show as-is.
     */
    public function summary(): string
    {
        return sprintf(
            '%d/100 %s - %d finding(s) across %d file(s)',
            $this->score,
            $this->label(),
            $this->findings,
            $this->files,
        );
    }

    /**
     * Whether this snapshot is older than the caller is willing to accept.
     *
     * A zero TTL means "always stale", which is how `--fresh` and a disabled
     * cache are expressed without a second flag threaded through every
     * surface.
     */
    public function isStale(int $ttl, ?int $now = null): bool
    {
        return ($now ?? time()) - $this->generatedAt >= $ttl;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => 1,
            'tool' => 'sloppy',
            'score' => $this->score,
            'band' => $this->band->value,
            'label' => $this->label(),
            'findings' => $this->findings,
            'files' => $this->files,
            'lines' => $this->lines,
            'by_severity' => $this->bySeverity,
            'by_category' => $this->byCategory,
            'top' => $this->top,
            'rules_skipped' => $this->skippedRules,
            'generated_at' => $this->generatedAt,
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function categories(AnalysisResult $result): array
    {
        $counts = [];

        foreach ($result->findings as $finding) {
            $counts[$finding->category->value] = ($counts[$finding->category->value] ?? 0) + 1;
        }

        $rows = [];

        foreach ($counts as $category => $count) {
            $rows[] = ['category' => (string) $category, 'count' => $count];
        }

        // Biggest category first: a widget with room for three bars should
        // draw the three that matter. Ties fall back to the enum's own order,
        // so two runs over the same code draw the same chart.
        usort($rows, static fn (array $a, array $b): int => [-$a['count'], self::position($a['category'])]
            <=> [-$b['count'], self::position($b['category'])]);

        $sorted = [];

        foreach ($rows as $row) {
            $sorted[$row['category']] = $row['count'];
        }

        return $sorted;
    }

    private static function position(string $category): int
    {
        $index = array_search($category, array_column(Category::cases(), 'value'), true);

        return is_int($index) ? $index : count(Category::cases());
    }

    /**
     * @return list<array{rule: string, name: string, severity: string, file: string, line: int, message: string, risk: float}>
     */
    private static function ranked(AnalysisResult $result, RiskCalculator $risk, int $top): array
    {
        $rows = [];

        foreach (array_slice($risk->rank($result->findings), 0, max(0, $top)) as $row) {
            $finding = $row['finding'];

            $rows[] = [
                'rule' => $finding->ruleId,
                'name' => $finding->ruleName,
                'severity' => $finding->severity->value,
                'file' => $finding->location->relativePath,
                'line' => $finding->location->line,
                'message' => $finding->message,
                'risk' => round($row['risk']->value, 2),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function intFrom(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    private static function countsFrom(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        $counts = [];

        /** @var mixed $count */
        foreach (is_array($value) ? $value : [] as $name => $count) {
            if (is_string($name) && is_int($count)) {
                $counts[$name] = $count;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function stringsFrom(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        $strings = [];

        /** @var mixed $entry */
        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_string($entry)) {
                $strings[] = $entry;
            }
        }

        return $strings;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{rule: string, name: string, severity: string, file: string, line: int, message: string, risk: float}>
     */
    private static function topFrom(array $data): array
    {
        $value = $data['top'] ?? null;
        $rows = [];

        /** @var mixed $row */
        foreach (is_array($value) ? $value : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $rows[] = [
                'rule' => self::stringFrom($row, 'rule'),
                'name' => self::stringFrom($row, 'name'),
                'severity' => self::stringFrom($row, 'severity'),
                'file' => self::stringFrom($row, 'file'),
                'line' => self::intFrom($row, 'line', 0),
                'message' => self::stringFrom($row, 'message'),
                'risk' => self::floatFrom($row, 'risk'),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function stringFrom(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function floatFrom(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }
}
