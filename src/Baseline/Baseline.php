<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Baseline;

use Heyosseus\Sloppy\Analysis\Finding;

/**
 * A snapshot of the debt a project has decided to live with for now.
 *
 * Entries are sorted by rule, file and fingerprint, so regenerating a baseline
 * over unchanged code produces an identical file and an empty diff.
 */
final readonly class Baseline
{
    public const int SCHEMA = 1;

    /**
     * @param  array<string, BaselineEntry>  $entries  Keyed by identity.
     */
    public function __construct(
        private array $entries = [],
        public string $generatedAt = '',
        public ?int $score = null,
    ) {}

    /**
     * @param  list<Finding>  $findings
     */
    public static function fromFindings(array $findings, string $generatedAt = '', ?int $score = null): self
    {
        /** @var array<string, BaselineEntry> $entries */
        $entries = [];

        foreach ($findings as $finding) {
            $id = $finding->identity();

            $entries[$id] = isset($entries[$id])
                ? $entries[$id]->withCount($entries[$id]->count + 1)
                : BaselineEntry::fromFinding($finding);
        }

        return new self($entries, $generatedAt, $score);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, BaselineEntry> $entries */
        $entries = [];

        /** @var mixed $raw */
        $raw = $data['entries'] ?? [];

        if (is_array($raw)) {
            /** @var mixed $row */
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }

                /** @var array<string, mixed> $row */
                $entry = BaselineEntry::fromArray($row);

                if ($entry instanceof BaselineEntry) {
                    $entries[$entry->id] = $entry;
                }
            }
        }

        $generatedAt = $data['generated_at'] ?? '';
        $score = $data['score'] ?? null;

        return new self(
            entries: $entries,
            generatedAt: is_string($generatedAt) ? $generatedAt : '',
            score: is_int($score) ? $score : null,
        );
    }

    /**
     * How many occurrences of this finding the baseline accepts.
     */
    public function allowanceFor(Finding $finding): int
    {
        $entry = $this->entries[$finding->identity()] ?? null;

        return $entry instanceof BaselineEntry ? $entry->count : 0;
    }

    public function contains(Finding $finding): bool
    {
        return isset($this->entries[$finding->identity()]);
    }

    /**
     * @return list<BaselineEntry>
     */
    public function entries(): array
    {
        $entries = array_values($this->entries);

        usort($entries, static fn (BaselineEntry $a, BaselineEntry $b): int => [$a->ruleId, $a->file, $a->fingerprint] <=> [$b->ruleId, $b->file, $b->fingerprint]);

        return $entries;
    }

    /**
     * Total accepted findings, counting repeats.
     */
    public function count(): int
    {
        $total = 0;

        foreach ($this->entries as $entry) {
            $total += $entry->count;
        }

        return $total;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'tool' => 'sloppy',
            'generated_at' => $this->generatedAt,
            'score' => $this->score,
            'total' => $this->count(),
            'entries' => array_map(
                static fn (BaselineEntry $entry): array => $entry->toArray(),
                $this->entries(),
            ),
        ];
    }
}
