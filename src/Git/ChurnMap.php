<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Git;

/**
 * How often each file changed in the project's recent history.
 *
 * A ninety-line method nobody has touched in years costs nothing until
 * somebody has to change it; the same method in a file that changes every
 * week costs something every week. This is the measure of that, and it only
 * ever moves the order findings are read in -- never the score, which is a
 * function of the tree alone.
 *
 * The window is a number of commits, not a length of time. "The last six
 * months" would make the same checkout rank differently next Tuesday, and
 * nothing else in this package depends on the clock.
 */
final readonly class ChurnMap
{
    /**
     * @param  array<string, int>  $changes  Relative path => commits that touched it.
     */
    public function __construct(private array $changes = []) {}

    /**
     * Read the last `$window` commits under the project root. Empty outside
     * a git repository, in one with no history, or with a window of zero.
     */
    public static function fromGit(Git $git, int $window): self
    {
        return $window > 0 ? new self($git->churn($window)) : new self;
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * Commits in the window that touched this file, or null when there is no
     * history to ask. A file the history never mentions changed zero times,
     * which is a measurement, not an absence of one.
     */
    public function forFile(string $relativePath): ?int
    {
        return $this->isEmpty() ? null : $this->changes[$relativePath] ?? 0;
    }
}
