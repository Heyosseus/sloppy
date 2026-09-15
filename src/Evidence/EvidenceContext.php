<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Evidence;

use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\Git;

/**
 * Everything an evidence source is allowed to look at.
 *
 * Deliberately the complement of {@see \Heyosseus\Sloppy\Analysis\AnalysisContext}:
 * that one carries a parsed file and forbids disk access, this one carries git
 * and a base revision and has no AST at all. A detector needing both would be a
 * detector doing two jobs.
 */
final readonly class EvidenceContext
{
    /**
     * @param  list<ChangedFile>  $changedFiles
     */
    public function __construct(
        public Git $git,
        public string $basePath,
        public string $baseRevision,
        public array $changedFiles,
    ) {}

    /**
     * @return list<string>
     */
    public function changedPaths(): array
    {
        return array_map(
            static fn (ChangedFile $file): string => $file->relativePath,
            $this->changedFiles,
        );
    }
}
