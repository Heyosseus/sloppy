<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use Heyosseus\Sloppy\Ast\ParsedFile;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;

/**
 * Everything a rule is allowed to look at.
 *
 * Rules receive this rather than a path, which is what keeps them from reading
 * or re-parsing files: one parse per file per run, shared by every rule.
 */
final readonly class AnalysisContext
{
    public function __construct(
        public ParsedFile $file,
        public ProjectIndex $index,
    ) {}

    public function relativePath(): string
    {
        return $this->file->relativePath;
    }

    /**
     * @return list<Stmt>
     */
    public function ast(): array
    {
        return $this->file->ast;
    }

    /**
     * @return list<ClassLike>
     */
    public function classLikes(): array
    {
        return $this->file->classLikes();
    }

    /**
     * Build a location from a node, spanning its full extent.
     */
    public function locate(Node $node): Location
    {
        return new Location(
            relativePath: $this->file->relativePath,
            line: max(1, $node->getStartLine()),
            endLine: $node->getEndLine() > 0 ? $node->getEndLine() : null,
            column: $this->columnOf($node),
        );
    }

    /**
     * Build a location for a single line.
     */
    public function locateLine(int $line): Location
    {
        return new Location(
            relativePath: $this->file->relativePath,
            line: max(1, $line),
        );
    }

    /**
     * 1-indexed column of a node, when the parser recorded file offsets.
     */
    private function columnOf(Node $node): ?int
    {
        $offset = $node->getStartFilePos();

        if ($offset < 0) {
            return null;
        }

        $lineStart = strrpos(substr($this->file->source, 0, $offset), "\n");

        return $lineStart === false ? $offset + 1 : $offset - $lineStart;
    }
}
