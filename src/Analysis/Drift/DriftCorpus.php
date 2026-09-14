<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis\Drift;

use Heyosseus\Sloppy\Ast\BlockSignature;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use WeakMap;

/**
 * Every drift answer a project has, worked out once.
 *
 * The search is a property of the project, not of the file being reported on:
 * it reads the whole index and returns the same pairs whichever file asks.
 * Rules, though, are handed one file at a time, so the obvious shape --
 * search, then keep the rows belonging to this file -- quietly does the whole
 * search once per file. On a 1,075-file application that is 0.28s of work
 * repeated 1,075 times: six minutes to produce an answer that takes a third
 * of a second to find.
 *
 * So the answer is computed once per index and handed out pre-sorted into the
 * file that will ask for it. The cache is a {@see WeakMap} keyed by the index
 * itself: a long-lived process -- the MCP server analysing one project after
 * another -- drops each corpus when its index goes, and identity comparison
 * cannot be fooled by a reused object id the way `spl_object_id()` can.
 */
final class DriftCorpus
{
    /** @var WeakMap<ProjectIndex, array<string, self>>|null */
    private static ?WeakMap $memo = null;

    /**
     * @param  array<string, list<DriftPair>>  $pairsByFile  Keyed by the path of the pair's subject.
     * @param  array<string, list<array{divergence: array{index: int, majority: string, minority: string, at: BlockSignature}, siblings: int}>>  $maskedByFile
     * @param  array<string, array<string, bool>>  $families  Body key => the identities it drifts with.
     */
    private function __construct(
        public bool $truncated,
        private array $pairsByFile,
        private array $maskedByFile,
        private array $families,
    ) {}

    /**
     * The corpus for this index and these thresholds, searched at most once.
     */
    public static function for(
        ProjectIndex $index,
        int $budget,
        float $maxRatio,
        int $minStatements,
        int $maxComparisons,
    ): self {
        self::$memo ??= new WeakMap;

        $key = implode('|', [$budget, $maxRatio, $minStatements, $maxComparisons]);
        $cached = self::$memo[$index] ?? [];

        if (isset($cached[$key])) {
            return $cached[$key];
        }

        $corpus = self::search($index, $budget, $maxRatio, $minStatements, $maxComparisons);
        $cached[$key] = $corpus;
        self::$memo[$index] = $cached;

        return $corpus;
    }

    /**
     * The pairs whose subject lives in this file, in corpus order.
     *
     * @return list<DriftPair>
     */
    public function pairsIn(string $relativePath): array
    {
        return $this->pairsByFile[$relativePath] ?? [];
    }

    /**
     * The masked divergences reported against this file, in corpus order.
     *
     * @return list<array{divergence: array{index: int, majority: string, minority: string, at: BlockSignature}, siblings: int}>
     */
    public function maskedIn(string $relativePath): array
    {
        return $this->maskedByFile[$relativePath] ?? [];
    }

    /**
     * How many bodies this one belongs to, itself included.
     */
    public function familySize(BlockSignature $of): int
    {
        $members = $this->families[self::bodyKey($of)] ?? [];
        $members[$of->identity()] = true;

        return count($members);
    }

    private static function search(
        ProjectIndex $index,
        int $budget,
        float $maxRatio,
        int $minStatements,
        int $maxComparisons,
    ): self {
        $finder = new DriftFinder(
            budget: $budget,
            maxRatio: $maxRatio,
            minStatements: $minStatements,
            maxComparisons: $maxComparisons,
        );

        $pairsByFile = [];
        $families = [];

        foreach ($finder->pairs($index) as $pair) {
            $pairsByFile[$pair->a->block->relativePath][] = $pair;

            // Both directions, because the family of a body is everything it
            // drifts with regardless of which side of the pair it landed on.
            $families[self::bodyKey($pair->a)][$pair->b->identity()] = true;
            $families[self::bodyKey($pair->b)][$pair->a->identity()] = true;
        }

        return new self(
            truncated: $finder->truncated(),
            pairsByFile: $pairsByFile,
            maskedByFile: self::maskedDivergences($index, $minStatements),
            families: $families,
        );
    }

    /**
     * @return array<string, list<array{divergence: array{index: int, majority: string, minority: string, at: BlockSignature}, siblings: int}>>
     */
    private static function maskedDivergences(ProjectIndex $index, int $minStatements): array
    {
        $byFile = [];

        foreach (self::hashGroups($index, $minStatements) as $group) {
            foreach (MaskedDivergence::inGroup($group) as $divergence) {
                $byFile[$divergence['at']->block->relativePath][] = [
                    'divergence' => $divergence,
                    'siblings' => count($group) - 1,
                ];
            }
        }

        return $byFile;
    }

    /**
     * Bodies grouped by structural hash, only where a majority could exist.
     *
     * The floor of three here is a policy choice about which groups are worth
     * examining, and it is deliberately a separate decision from the identical
     * floor inside {@see MaskedDivergence}, which is a soundness precondition:
     * with two bodies there is a difference but no way to say which is the
     * mistake. Raising this one does not make that one adjustable.
     *
     * @return list<list<BlockSignature>>
     */
    private static function hashGroups(ProjectIndex $index, int $minStatements): array
    {
        $groups = [];

        foreach ($index->blockSignatures() as $signature) {
            if ($signature->block->statementCount < $minStatements) {
                continue;
            }

            $groups[$signature->hash][] = $signature;
        }

        return array_values(array_filter($groups, static fn (array $group): bool => count($group) >= 3));
    }

    /**
     * What makes two signatures the same body, matching
     * {@see BlockSignature::isSameBodyAs()}.
     */
    private static function bodyKey(BlockSignature $signature): string
    {
        return $signature->block->relativePath
            .'|'.$signature->block->className
            .'|'.$signature->block->methodName;
    }
}
