<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;

/**
 * One PHP file, parsed once and reused by every rule.
 *
 * Rules never re-read or re-parse a file: they receive this.
 */
final class ParsedFile
{
    /** @var list<string> */
    public readonly array $lines;

    /** @var list<ClassLike>|null */
    private ?array $classLikes = null;

    /**
     * @param  list<Stmt>  $ast  Empty when the file could not be parsed.
     */
    public function __construct(
        public readonly string $relativePath,
        public readonly string $absolutePath,
        public readonly string $source,
        public readonly array $ast,
        public readonly ?string $parseError = null,
    ) {
        $this->lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $source));
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }

    /**
     * Significant lines of code: blank lines and comment-only lines excluded.
     *
     * This is what the score normalises against, so that a file padded with
     * generated docblocks does not dilute its own penalties.
     */
    public function codeLineCount(): int
    {
        $count = 0;

        foreach ($this->lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    public function lineAt(int $line): ?string
    {
        return $this->lines[$line - 1] ?? null;
    }

    /**
     * Source text between two 1-indexed lines, inclusive.
     */
    public function snippet(int $from, int $to): string
    {
        $from = max(1, $from);
        $to = min($this->lineCount(), max($from, $to));

        return implode("\n", array_slice($this->lines, $from - 1, $to - $from + 1));
    }

    public function isParsed(): bool
    {
        return $this->parseError === null;
    }

    /**
     * Every class, interface, trait and enum declared in the file.
     *
     * @return list<ClassLike>
     */
    public function classLikes(): array
    {
        if ($this->classLikes === null) {
            /** @var list<ClassLike> $found */
            $found = (new NodeFinder)->findInstanceOf($this->ast, ClassLike::class);

            $this->classLikes = $found;
        }

        return $this->classLikes;
    }

    /**
     * The file's namespace, or null for namespaceless files.
     */
    public function namespaceName(): ?string
    {
        foreach ($this->ast as $statement) {
            if ($statement instanceof Namespace_ && $statement->name instanceof Name) {
                return $statement->name->toString();
            }
        }

        return null;
    }
}
