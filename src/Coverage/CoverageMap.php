<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Coverage;

/**
 * How much of each file the test suite executed, where that is known.
 *
 * Every lookup can answer "I do not know", and that answer is null rather than
 * zero on purpose: a file the report never mentions has not been measured, and
 * treating it as untested would send every interface and every excluded
 * directory to the top of the reading order.
 *
 * The source path and timestamp are carried so `sloppy health` and
 * `--explain-risk` can say which report this came from and when it was written.
 * Sloppy does not act on that age: a staleness heuristic would be a number that
 * cannot show its arithmetic, which is exactly what this package refuses to
 * ship.
 */
final readonly class CoverageMap
{
    /**
     * @var array<string, float>
     */
    private array $ratios;

    /**
     * @param  array<string, float>  $ratios  Relative path => 0.0-1.0.
     * @param  int|null  $generatedAt  The report's modification time.
     */
    public function __construct(
        array $ratios = [],
        private ?string $sourcePath = null,
        private ?int $generatedAt = null,
    ) {
        $normalised = [];

        foreach ($ratios as $path => $ratio) {
            $normalised[$this->normalise($path)] = $ratio;
        }

        $this->ratios = $normalised;
    }

    public static function empty(): self
    {
        return new self;
    }

    public function forFile(string $relativePath): ?float
    {
        return $this->ratios[$this->normalise($relativePath)] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->ratios === [];
    }

    public function sourcePath(): ?string
    {
        return $this->sourcePath;
    }

    public function generatedAt(): ?int
    {
        return $this->generatedAt;
    }

    private function normalise(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), './');
    }
}
