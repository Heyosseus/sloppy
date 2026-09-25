<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

use PhpParser\Modifiers;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
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
     * @param  list<BlockSignature>  $blockSignatures  Ascending by token count; see blockSignatures().
     */
    public function __construct(
        private array $classes = [],
        private array $implementations = [],
        private array $usages = [],
        private array $duplicateBlocks = [],
        private array $blockSignatures = [],
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

        /** @var list<BlockSignature> $signatures */
        $signatures = [];

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

                self::collectDuplicates($classLike, $file, $fqn, $duplicates, $signatures);
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

        // Ascending token count, with a total tie-break so the window SL111
        // walks is the same on every machine and every run.
        usort($signatures, static fn (BlockSignature $a, BlockSignature $b): int => [
            $a->tokenCount, $a->block->relativePath, $a->block->line,
        ] <=> [
            $b->tokenCount, $b->block->relativePath, $b->block->line,
        ]);

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
            blockSignatures: $signatures,
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

    /**
     * Whether a class is one of the given bases or extends one, following
     * parents through the index for as long as the project declares them.
     * Pass a class's parent to ask whether the class extends a base.
     *
     * @param  list<string>  $bases
     */
    public function extendsAny(?string $class, array $bases): bool
    {
        $seen = [];

        while ($class !== null && ! isset($seen[$class])) {
            if (in_array($class, $bases, true)) {
                return true;
            }

            $seen[$class] = true;
            $class = $this->classes[$class]->parent ?? null;
        }

        return false;
    }

    /**
     * Every indexed trait a class composes, including traits used by those
     * traits. Traits outside the analysed paths are not indexed and so are
     * not returned.
     *
     * @return list<ClassSummary>
     */
    public function traitsOf(string $fqn): array
    {
        $found = [];
        $pending = $this->classes[$fqn]->traits ?? [];

        while ($pending !== []) {
            $name = array_shift($pending);
            $trait = $this->classes[$name] ?? null;

            if (isset($found[$name]) || $trait === null || $trait->kind !== 'trait') {
                continue;
            }

            $found[$name] = $trait;
            $pending = [...$pending, ...$trait->traits];
        }

        return array_values($found);
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
     * Every indexed method body, ascending by token count.
     *
     * The order is the point: SL111 compares a body only against bodies whose
     * token counts are within its edit budget, which is a sliding window over
     * this list rather than a scan of all pairs. On a 1,075-file application
     * that is 9,526 comparisons instead of 152,076.
     *
     * @return list<BlockSignature>
     */
    public function blockSignatures(): array
    {
        return $this->blockSignatures;
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
            traits: self::traitNames($classLike),
            propertyReads: $classLike instanceof Trait_ ? self::propertyReads($classLike) : [],
            calledNames: $classLike instanceof Trait_ ? self::calledNames($classLike) : [],
            hasDynamicAccess: $classLike instanceof Trait_ && NodeHelper::hasDynamicAccess($classLike),
            isValueObject: $classLike instanceof Class_ && self::isValueObject($classLike),
        );
    }

    /**
     * A class that holds data rather than behaviour: declared `readonly`, or
     * built only from readonly promoted properties.
     */
    private static function isValueObject(Class_ $class): bool
    {
        if ($class->isReadonly()) {
            return true;
        }

        $params = NodeHelper::constructorParams($class);

        foreach ($params as $param) {
            if (($param->flags & Modifiers::READONLY) === 0) {
                return false;
            }
        }

        return $params !== [];
    }

    /**
     * @return list<string>
     */
    private static function traitNames(ClassLike $classLike): array
    {
        $names = [];

        foreach ($classLike->stmts as $statement) {
            if (! $statement instanceof TraitUse) {
                continue;
            }

            foreach ($statement->traits as $trait) {
                $names[] = $trait->toString();
            }
        }

        return $names;
    }

    /**
     * Every `$this->name` a trait reads. Its own `$this->name = ...` is a
     * write, not a read.
     *
     * @return list<string>
     */
    private static function propertyReads(Trait_ $trait): array
    {
        $names = [];

        foreach (NodeHelper::find($trait, PropertyFetch::class) as $fetch) {
            if (! $fetch->name instanceof Identifier || ! $fetch->var instanceof Variable || $fetch->var->name !== 'this') {
                continue;
            }

            $parent = $fetch->getAttribute('parent');

            if ($parent instanceof Assign && $parent->var === $fetch) {
                continue;
            }

            $names[$fetch->name->toString()] = true;
        }

        return array_keys($names);
    }

    /**
     * Method names a trait calls, on any receiver, plus every string literal
     * in it -- the same evidence SL105 accepts inside the class itself.
     *
     * @return list<string>
     */
    private static function calledNames(Trait_ $trait): array
    {
        $names = [];

        foreach ([MethodCall::class, NullsafeMethodCall::class, StaticCall::class] as $type) {
            foreach (NodeHelper::find($trait, $type) as $call) {
                $name = NodeHelper::callName($call);

                if ($name !== null) {
                    $names[mb_strtolower($name)] = true;
                }
            }
        }

        foreach (NodeHelper::find($trait, String_::class) as $string) {
            $names[mb_strtolower($string->value)] = true;
        }

        return array_map(strval(...), array_keys($names));
    }

    /**
     * @param  array<string, list<DuplicateBlock>>  $duplicates
     * @param  list<BlockSignature>  $signatures
     */
    private static function collectDuplicates(
        ClassLike $classLike,
        ParsedFile $file,
        string $fqn,
        array &$duplicates,
        array &$signatures,
    ): void {
        foreach (NodeHelper::methods($classLike) as $method) {
            if ($method->stmts === null || $method->stmts === []) {
                continue;
            }

            $tokens = [];
            $masked = [];

            foreach ($method->stmts as $statement) {
                $signature = NodeHelper::signature($statement);
                $tokens = [...$tokens, ...$signature->tokens];
                $masked = [...$masked, ...$signature->maskedValues];
            }

            $block = new DuplicateBlock(
                relativePath: $file->relativePath,
                className: NodeHelper::baseName($fqn),
                methodName: $method->name->toString(),
                line: $method->getStartLine(),
                endLine: $method->getEndLine(),
                statementCount: NodeHelper::countStatements($method),
            );

            // Equal to blockHash(): concatenating each statement's joined
            // token string is the same as joining the full concatenation, so
            // this must never be allowed to drift from blockHash() above.
            $hash = hash('sha256', implode('', $tokens));

            $duplicates[$hash][] = $block;
            $signatures[] = BlockSignature::create($block, $hash, $tokens, $masked);
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
