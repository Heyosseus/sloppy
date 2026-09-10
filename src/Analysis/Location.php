<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use Stringable;

/**
 * Where in the project a finding lives.
 *
 * `relativePath` is what reports and baselines use, so results stay identical
 * regardless of where the project is checked out.
 */
final readonly class Location implements Stringable
{
    public function __construct(
        public string $relativePath,
        public int $line,
        public ?int $endLine = null,
        public ?int $column = null,
    ) {}

    /**
     * Number of source lines the finding spans. Feeds the score, which cares
     * how much of the codebase a problem covers, not only how many problems
     * there are.
     */
    public function affectedLines(): int
    {
        if ($this->endLine === null || $this->endLine < $this->line) {
            return 1;
        }

        return $this->endLine - $this->line + 1;
    }

    public function __toString(): string
    {
        $rendered = $this->relativePath.':'.$this->line;

        if ($this->column !== null) {
            $rendered .= ':'.$this->column;
        }

        return $rendered;
    }
}
