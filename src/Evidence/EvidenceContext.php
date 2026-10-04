<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Evidence;

use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\Git;

/**
 * Everything an evidence source is allowed to look at.
 *
 * Deliberately the complement of {@see \Heyosseus\Sloppy\Analysis\AnalysisContext}:
 * that one carries a parsed file and forbids disk access, this one carries git
 * and a base revision and no file's AST. A detector needing both would be a
 * detector doing two jobs.
 *
 * It does carry the project index of the working tree, when the diff built
 * one: "which classes did this change add, and what are they?" is a question
 * about the change that only the index can answer the way the rules would.
 */
final readonly class EvidenceContext
{
    /**
     * @param  list<ChangedFile>  $changedFiles
     * @param  ProjectIndex|null  $index  The working tree's index; null when no analysable file changed.
     */
    public function __construct(
        public Git $git,
        public string $basePath,
        public string $baseRevision,
        public array $changedFiles,
        public ?ProjectIndex $index = null,
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
