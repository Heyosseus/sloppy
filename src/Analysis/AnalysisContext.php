<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use Heyosseus\Sloppy\Architecture\ArchitectureMap;
use Heyosseus\Sloppy\Architecture\Role;
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
        public ArchitectureMap $architecture = new ArchitectureMap,
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
     * The role a declaration in this file plays in the project's architecture
     * -- `controller`, `model`, or whatever the project named it -- or null
     * when no role matches.
     *
     * @api
     */
    public function roleOf(ClassLike $class): ?string
    {
        return $this->role($class)?->name;
    }

    /**
     * The full role a declaration plays, with where it was defined and
     * whether its abstractions are intended.
     *
     * @api
     */
    public function role(ClassLike $class): ?Role
    {
        return $this->architecture->matchNode($class, $this->file->relativePath, $this->index)->role;
    }

    /**
     * @api
     */
    public function hasRole(ClassLike $class, string $role): bool
    {
        return $this->roleOf($class) === $role;
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
