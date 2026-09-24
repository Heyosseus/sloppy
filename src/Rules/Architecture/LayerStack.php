<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Architecture;

use Closure;
use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\ProjectIndex;

/**
 * Classes in a project grouped by the noun their names are built around.
 *
 * `Order`, `OrderRepositoryInterface`, `OrderRepository` and `OrderService` are
 * four names for one concept. Two architecture rules need that grouping, and
 * they must agree on it, so it lives here.
 */
final readonly class LayerStack
{
    /**
     * Suffixes that name a layer rather than a thing.
     *
     * @var list<string>
     */
    public const array LAYER_SUFFIXES = [
        'Interface', 'Contract', 'Repository', 'Service', 'Manager', 'Factory', 'Handler',
        'Provider', 'Adapter', 'Wrapper', 'Decorator', 'Proxy', 'Impl', 'Implementation',
        'Abstract', 'Base', 'Builder', 'Resolver', 'Mapper', 'Transformer',
    ];

    /**
     * @param  list<ClassSummary>  $members  Every declaration built on this noun.
     */
    public function __construct(
        public string $noun,
        public array $members,
    ) {}

    /**
     * Group a project's declarations by base noun.
     *
     * @param  list<string>  $suffixes
     * @return array<string, self>
     */
    public static function group(ProjectIndex $index, array $suffixes = self::LAYER_SUFFIXES): array
    {
        /** @var array<string, list<ClassSummary>> $grouped */
        $grouped = [];

        // `baseNoun()` never strips a name down to nothing -- a class called
        // exactly `Service` keeps its name -- so every class belongs to some
        // group, even if that group has one member and forms no stack.
        foreach ($index->classes() as $summary) {
            $grouped[$summary->baseNoun($suffixes)][] = $summary;
        }

        $stacks = [];

        foreach ($grouped as $noun => $members) {
            usort($members, static fn (ClassSummary $a, ClassSummary $b): int => [$a->shortName, $a->relativePath] <=> [$b->shortName, $b->relativePath]);

            $stacks[$noun] = new self($noun, $members);
        }

        ksort($stacks);

        return $stacks;
    }

    /**
     * The layer part of a member's name: `OrderRepository` in the `Order`
     * stack is the `Repository` layer. The concept itself has no layer.
     */
    public function layerOf(ClassSummary $summary): string
    {
        return mb_substr($summary->shortName, mb_strlen($this->noun));
    }

    /**
     * Distinct wrapping layers around the concept, excluding the concept
     * itself. `Order` alone is not a stack; `Order` plus a repository, its
     * interface and a service is three layers deep.
     *
     * @return list<string>
     */
    public function layers(): array
    {
        $layers = [];

        foreach ($this->members as $member) {
            $layer = $this->layerOf($member);

            if ($layer !== '') {
                $layers[$layer] = true;
            }
        }

        $names = array_keys($layers);
        sort($names);

        return $names;
    }

    public function depth(): int
    {
        return count($this->layers());
    }

    /**
     * @return list<string>
     */
    public function memberNames(): array
    {
        return array_map(static fn (ClassSummary $member): string => $member->shortName, $this->members);
    }

    public function member(string $fqn): ?ClassSummary
    {
        foreach ($this->members as $member) {
            if ($member->fqn === $fqn) {
                return $member;
            }
        }

        return null;
    }

    /**
     * The same stack without the members a predicate rejects.
     *
     * @param  Closure(ClassSummary): bool  $exclude
     */
    public function without(Closure $exclude): self
    {
        return new self($this->noun, array_values(array_filter(
            $this->members,
            static fn (ClassSummary $member): bool => ! $exclude($member),
        )));
    }
}
