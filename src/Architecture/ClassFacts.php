<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use PhpParser\Node\Stmt\ClassLike;

/**
 * What a role matcher may look at about one declaration.
 *
 * Built from an AST node when a rule asks about the class it is analysing,
 * and from a {@see ClassSummary} when a command asks about the whole project.
 * Both roads end here so a class cannot get one role in a finding and another
 * in `sloppy architecture`.
 */
final readonly class ClassFacts
{
    /**
     * @param  string  $fqn  Empty for an anonymous class.
     * @param  'class'|'interface'|'trait'|'enum'  $kind
     * @param  list<string>  $ancestors  The parent first, then its parents for as long as the project declares them.
     * @param  list<string>  $interfaces
     * @param  list<string>  $traits
     * @param  list<string>  $attributes
     */
    public function __construct(
        public string $fqn,
        public string $shortName,
        public string $kind,
        public string $relativePath,
        public ?string $parent,
        public array $ancestors,
        public array $interfaces,
        public array $traits,
        public array $attributes,
    ) {}

    public static function fromNode(ClassLike $class, string $relativePath, ProjectIndex $index): self
    {
        $parent = NodeHelper::parentName($class);

        /** @var 'class'|'interface'|'trait'|'enum' $kind */
        $kind = NodeHelper::kindOf($class);

        return new self(
            fqn: NodeHelper::className($class) ?? '',
            shortName: NodeHelper::shortName($class) ?? '',
            kind: $kind,
            relativePath: $relativePath,
            parent: $parent,
            ancestors: self::ancestors($parent, $index),
            interfaces: NodeHelper::interfaceNames($class),
            traits: NodeHelper::traitNames($class),
            attributes: NodeHelper::attributeNames($class),
        );
    }

    public static function fromSummary(ClassSummary $summary, ProjectIndex $index): self
    {
        return new self(
            fqn: $summary->fqn,
            shortName: $summary->shortName,
            kind: $summary->kind,
            relativePath: $summary->relativePath,
            parent: $summary->parent,
            ancestors: self::ancestors($summary->parent, $index),
            interfaces: $summary->interfaces,
            traits: $summary->traits,
            attributes: $summary->attributes,
        );
    }

    /**
     * @return list<string>
     */
    private static function ancestors(?string $parent, ProjectIndex $index): array
    {
        $ancestors = [];

        while ($parent !== null && ! in_array($parent, $ancestors, true)) {
            $ancestors[] = $parent;
            $parent = $index->class($parent)?->parent;
        }

        return $ancestors;
    }
}
