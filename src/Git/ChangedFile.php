<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

/**
 * One file the diff touched, with the line ranges that changed in it.
 */
final readonly class ChangedFile
{
    /**
     * @param  'added'|'modified'|'renamed'|'deleted'|'untracked'  $status
     * @param  list<DiffHunk>  $hunks
     */
    public function __construct(
        public string $relativePath,
        public string $status,
        public array $hunks = [],
        public ?string $previousPath = null,
    ) {}

    public function existedBefore(): bool
    {
        return in_array($this->status, ['modified', 'renamed', 'deleted'], true);
    }

    public function isAnalysable(): bool
    {
        return $this->status !== 'deleted' && str_ends_with($this->relativePath, '.php');
    }

    /**
     * Whether the diff added or changed this line.
     */
    public function touches(int $line): bool
    {
        if ($this->status === 'added' || $this->status === 'untracked') {
            return true;
        }

        foreach ($this->hunks as $hunk) {
            if ($hunk->contains($line)) {
                return true;
            }
        }

        return false;
    }

    public function changedLineCount(): int
    {
        $count = 0;

        foreach ($this->hunks as $hunk) {
            $count += $hunk->lineCount;
        }

        return $count;
    }
}
