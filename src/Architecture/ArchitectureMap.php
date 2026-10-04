<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use PhpParser\Node\Stmt\ClassLike;
use WeakMap;

/**
 * The one answer to "what role does this class play?".
 *
 * Every rule asks here rather than guessing from names itself, so two rules
 * can never disagree about what a class is, and a project that describes its
 * architecture changes every rule's answer at once.
 *
 * @api Custom rules reach this through {@see \Heyosseus\Sloppy\Analysis\AnalysisContext::roleOf()}.
 */
final readonly class ArchitectureMap
{
    public Profile $profile;

    /**
     * Answers for nodes already asked about, so the five rules that ask about
     * the same class pay for matching once.
     *
     * @var WeakMap<ClassLike, RoleMatch>
     */
    private WeakMap $answers;

    public function __construct(?Profile $profile = null)
    {
        $this->profile = $profile ?? Profile::default();
        $this->answers = new WeakMap;
    }

    public function match(ClassFacts $facts): RoleMatch
    {
        $matching = array_values(array_filter(
            $this->profile->roles,
            static fn (Role $role): bool => $role->matches($facts),
        ));

        return new RoleMatch($matching[0] ?? null, array_slice($matching, 1));
    }

    public function matchNode(ClassLike $class, string $relativePath, ProjectIndex $index): RoleMatch
    {
        $answers = $this->answers;

        return $answers[$class] ??= $this->match(ClassFacts::fromNode($class, $relativePath, $index));
    }

    /**
     * A declared class's role, or null when the index does not know the class.
     */
    public function matchClass(string $fqn, ProjectIndex $index): ?RoleMatch
    {
        $summary = $index->class($fqn);

        return $summary instanceof ClassSummary ? $this->matchSummary($summary, $index) : null;
    }

    public function matchSummary(ClassSummary $summary, ProjectIndex $index): RoleMatch
    {
        return $this->match(ClassFacts::fromSummary($summary, $index));
    }
}
