<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Scoring\Score;

/**
 * What changed, in findings, between a revision and the working tree.
 *
 * The purpose of this object is to answer one question: did this change make
 * things worse? "Existing" findings are the ones the change inherited and left
 * alone; only "new" ones are the change's own doing.
 */
final readonly class DiffReport
{
    /**
     * @param  list<ChangedFile>  $changedFiles
     * @param  list<Finding>  $new  Present now, absent at the base revision.
     * @param  list<Finding>  $existing  Present in both.
     * @param  list<Finding>  $resolved  Present at the base revision, gone now.
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public string $base,
        public array $changedFiles,
        public array $new,
        public array $existing,
        public array $resolved,
        public Score $currentScore,
        public Score $baseScore,
        public array $errors = [],
    ) {}

    public function changedFileCount(): int
    {
        return count($this->changedFiles);
    }

    public function changedLineCount(): int
    {
        $count = 0;

        foreach ($this->changedFiles as $file) {
            $count += $file->changedLineCount();
        }

        return $count;
    }

    /**
     * @return list<Finding>
     */
    public function newAtOrAbove(Severity $threshold): array
    {
        return array_values(array_filter(
            $this->new,
            static fn (Finding $finding): bool => $finding->severity->isAtLeast($threshold),
        ));
    }

    public function scoreDelta(): int
    {
        return $this->currentScore->value - $this->baseScore->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => 1,
            'tool' => 'sloppy',
            'mode' => 'diff',
            'base' => $this->base,
            'changed_files' => array_map(
                static fn (ChangedFile $file): array => [
                    'path' => $file->relativePath,
                    'status' => $file->status,
                    'changed_lines' => $file->changedLineCount(),
                ],
                $this->changedFiles,
            ),
            'score' => [
                'base' => $this->baseScore->toArray(),
                'current' => $this->currentScore->toArray(),
                'delta' => $this->scoreDelta(),
            ],
            'summary' => [
                'new' => count($this->new),
                'existing' => count($this->existing),
                'resolved' => count($this->resolved),
            ],
            'new' => array_map(static fn (Finding $finding): array => $finding->toArray(), $this->new),
            'existing' => array_map(static fn (Finding $finding): array => $finding->toArray(), $this->existing),
            'resolved' => array_map(static fn (Finding $finding): array => $finding->toArray(), $this->resolved),
            'errors' => $this->errors,
        ];
    }
}
