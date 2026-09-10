<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\UseItem;

/**
 * A whole-project view, built in one pass before any rule runs.
 *
 * Rules that only look at a single file cannot answer questions like "is this
 * the only implementation of that interface?" or "does this method body appear
 * somewhere else too?". This index answers them without every architecture rule
 * re-walking the codebase.
 */
final readonly class ProjectIndex
{
    /**
     * @param  array<string, ClassSummary>  $classes  Keyed by fully qualified name.
     * @param  array<string, list<string>>  $implementations  Interface FQN => implementing class FQNs.
     * @param  array<string, list<string>>  $usages  Referenced FQN => relative paths that use it.
     * @param  array<string, list<DuplicateBlock>>  $duplicateBlocks  Structural hash => occurrences.
     */
    public function __construct(
        private array $classes = [],
        private array $implementations = [],
        private array $usages = [],
        private array $duplicateBlocks = [],
    ) {}

    /**
     * @param  list<ParsedFile>  $files
     */
    public static function build(array $files): self
    {
        /** @var array<string, ClassSummary> $classes */
        $classes = [];

        /** @var array<string, list<string>> $implementations */
        $implementations = [];

        /** @var array<string, array<string, true>> $usages */
        $usages = [];

        /** @var array<string, list<DuplicateBlock>> $duplicates */
        $duplicates = [];

        foreach ($files as $file) {
            $declared = [];
            $inheritanceNames = [];

            foreach ($file->classLikes() as $classLike) {
                $fqn = NodeHelper::className($classLike);

                if ($fqn === null) {
                    continue;
                }

                $declared[$fqn] = true;
                $classes[$fqn] = self::summarize($classLike, $file, $fqn);

                foreach (self::inheritanceClauses($classLike) as $name) {
                    $inheritanceNames[spl_object_id($name)] = true;
                }

                foreach (NodeHelper::interfaceNames($classLike) as $interface) {
                    if ($classLike instanceof Class_) {
                        $implementations[$interface][] = $fqn;
                    }
                }

                $parent = NodeHelper::parentName($classLike);

                if ($parent !== null && $classLike instanceof Class_) {
                    $implementations[$parent][] = $fqn;
                }

                self::collectDuplicates($classLike, $file, $fqn, $duplicates);
            }

            foreach (NodeHelper::find($file->ast, Name::class) as $name) {
                if (isset($inheritanceNames[spl_object_id($name)])) {
                    continue;
                }

                if (NodeHelper::closestAncestor($name, UseItem::class) instanceof UseItem) {
                    continue;
                }

                $referenced = $name->toString();

                if (isset($declared[$referenced])) {
                    continue;
                }

                $usages[$referenced][$file->relativePath] = true;
            }
        }

        return new self(
            classes: $classes,
            implementations: array_map(
                static fn (array $list): array => array_values(array_unique($list)),
                $implementations,
            ),
            usages: array_map(
                array_keys(...),
                $usages,
            ),
            duplicateBlocks: $duplicates,
        );
    }

    /**
     * @return array<string, ClassSummary>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    public function class(string $fqn): ?ClassSummary
    {
        return $this->classes[$fqn] ?? null;
    }

    /**
     * Classes that extend or implement the given name.
     *
     * @return list<string>
     */
    public function implementationsOf(string $fqn): array
    {
        return $this->implementations[$fqn] ?? [];
    }

    /**
     * Files that mention a name somewhere other than an extends/implements
     * clause or an import statement.
     *
     * @return list<string>
     */
    public function usagesOf(string $fqn): array
    {
        return $this->usages[$fqn] ?? [];
    }

    public function usageCount(string $fqn): int
    {
        return count($this->usagesOf($fqn));
    }

    /**
     * Occurrences sharing a structural hash, including the one being asked
     * about.
     *
     * @return list<DuplicateBlock>
     */
    public function blocksMatching(string $hash): array
    {
        return $this->duplicateBlocks[$hash] ?? [];
    }

    /**
     * @return array<string, list<DuplicateBlock>>
     */
    public function duplicateBlocks(): array
    {
        return $this->duplicateBlocks;
    }

    /**
     * Structural hash of a method body, ignoring local variable names and
     * literal values but keeping control flow and the names of things called.
     *
     * The duplicate-logic rule recomputes this for the method it is looking at
     * and asks the index who else hashes the same, so the hashing lives here
     * and cannot drift between the two.
     */
    public static function blockHash(ClassMethod $method): string
    {
        $structure = '';

        foreach ($method->stmts ?? [] as $statement) {
            $structure .= NodeHelper::structure($statement);
        }

        return hash('sha256', $structure);
    }

    private static function summarize(ClassLike $classLike, ParsedFile $file, string $fqn): ClassSummary
    {
        $methods = NodeHelper::methods($classLike);

        /** @var 'class'|'interface'|'trait'|'enum' $kind */
        $kind = NodeHelper::kindOf($classLike);

        return new ClassSummary(
            fqn: $fqn,
            shortName: NodeHelper::shortName($classLike) ?? NodeHelper::baseName($fqn),
            kind: $kind,
            relativePath: $file->relativePath,
            line: $classLike->getStartLine(),
            parent: NodeHelper::parentName($classLike),
            interfaces: NodeHelper::interfaceNames($classLike),
            methodCount: count($methods),
            publicMethodNames: array_values(array_map(
                static fn (ClassMethod $method): string => $method->name->toString(),
                NodeHelper::publicMethods($classLike),
            )),
            statementCount: NodeHelper::countStatements($classLike),
            lineSpan: NodeHelper::lineSpan($classLike),
            dependencyCount: NodeHelper::countDependencies($classLike),
            isAbstract: $classLike instanceof Class_ && $classLike->isAbstract(),
        );
    }

    /**
     * @param  array<string, list<DuplicateBlock>>  $duplicates
     */
    private static function collectDuplicates(ClassLike $classLike, ParsedFile $file, string $fqn, array &$duplicates): void
    {
        foreach (NodeHelper::methods($classLike) as $method) {
            if ($method->stmts === null || $method->stmts === []) {
                continue;
            }

            $duplicates[self::blockHash($method)][] = new DuplicateBlock(
                relativePath: $file->relativePath,
                className: NodeHelper::baseName($fqn),
                methodName: $method->name->toString(),
                line: $method->getStartLine(),
                endLine: $method->getEndLine(),
                statementCount: NodeHelper::countStatements($method),
            );
        }
    }

    /**
     * The `Name` nodes sitting in extends/implements position, so they can be
     * excluded from usage counts: implementing an interface is not the same as
     * consuming it.
     *
     * @return list<Name>
     */
    private static function inheritanceClauses(ClassLike $classLike): array
    {
        $names = [];

        if ($classLike instanceof Class_) {
            if ($classLike->extends instanceof Name) {
                $names[] = $classLike->extends;
            }

            foreach ($classLike->implements as $interface) {
                $names[] = $interface;
            }
        }

        if ($classLike instanceof Interface_) {
            foreach ($classLike->extends as $interface) {
                $names[] = $interface;
            }
        }

        return $names;
    }
}
