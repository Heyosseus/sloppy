<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

/**
 * One occurrence of a structurally identical block of code.
 */
final readonly class DuplicateBlock
{
    public function __construct(
        public string $relativePath,
        public string $className,
        public string $methodName,
        public int $line,
        public int $endLine,
        public int $statementCount,
    ) {}

    public function label(): string
    {
        return $this->className.'::'.$this->methodName.'()';
    }

    public function reference(): string
    {
        return $this->relativePath.':'.$this->line;
    }
}
