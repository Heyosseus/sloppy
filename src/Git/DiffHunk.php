<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

/**
 * A run of lines a diff added to the new version of a file.
 */
final readonly class DiffHunk
{
    public function __construct(
        public int $startLine,
        public int $lineCount,
    ) {}

    public function endLine(): int
    {
        return $this->startLine + max(0, $this->lineCount - 1);
    }

    public function contains(int $line): bool
    {
        return $this->lineCount > 0
            && $line >= $this->startLine
            && $line <= $this->endLine();
    }
}
