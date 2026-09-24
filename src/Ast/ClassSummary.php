<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

/**
 * What the project index remembers about one declaration.
 *
 * Architecture rules need to reason about classes they are not currently
 * looking at -- "does this interface have exactly one implementation, and is
 * that implementation trivial?" -- without holding every AST in memory.
 */
final readonly class ClassSummary
{
    /**
     * @param  'class'|'interface'|'trait'|'enum'  $kind
     * @param  list<string>  $interfaces
     * @param  list<string>  $publicMethodNames
     * @param  list<string>  $traits  Fully qualified names of the traits it `use`s directly.
     * @param  list<string>  $propertyReads  Traits only: every `$this->name` the body reads.
     * @param  list<string>  $calledNames  Traits only: lower-cased method names it calls or names in a string.
     * @param  bool  $isValueObject  A `readonly` class, or one whose constructor only promotes readonly properties.
     */
    public function __construct(
        public string $fqn,
        public string $shortName,
        public string $kind,
        public string $relativePath,
        public int $line,
        public ?string $parent,
        public array $interfaces,
        public int $methodCount,
        public array $publicMethodNames,
        public int $statementCount,
        public int $lineSpan,
        public int $dependencyCount,
        public bool $isAbstract,
        public array $traits = [],
        public array $propertyReads = [],
        public array $calledNames = [],
        public bool $hasDynamicAccess = false,
        public bool $isValueObject = false,
    ) {}

    /**
     * Whether this declaration is an Eloquent model.
     *
     * The query rules use this to tell `Order::find(1)` from a static helper
     * that happens to be called `find`.
     */
    public function isEloquentModel(): bool
    {
        return $this->kind === 'class' && NodeHelper::extendsEloquentModel($this->parent);
    }

    /**
     * Whether the declaration is small enough that wrapping it in another layer
     * is unlikely to be earning its keep.
     */
    public function isTrivial(int $maxStatements = 12, int $maxMethods = 3): bool
    {
        return $this->statementCount <= $maxStatements && $this->methodCount <= $maxMethods;
    }

    /**
     * The noun a layered name is built around: `OrderRepository` -> `Order`,
     * `OrderServiceInterface` -> `Order`.
     *
     * @param  list<string>  $layerSuffixes
     */
    public function baseNoun(array $layerSuffixes): string
    {
        $name = $this->shortName;

        // Strip repeatedly so `OrderRepositoryInterface` loses both suffixes.
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach ($layerSuffixes as $suffix) {
                if ($name !== $suffix && str_ends_with($name, $suffix)) {
                    $name = mb_substr($name, 0, -mb_strlen($suffix));
                    $changed = true;
                }
            }
        }

        return $name;
    }
}
