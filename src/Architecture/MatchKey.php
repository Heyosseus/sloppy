<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * The parts of a declaration a role can be matched on.
 *
 * The vocabulary follows deptrac's collectors where they overlap, so a team
 * moving a `deptrac.yaml` over is translating, not redesigning.
 */
enum MatchKey: string
{
    /** A glob on the fully qualified name: `App\Domain\*\Actions\*`. */
    case Namespace = 'namespace';

    /** A glob on the project-relative path: `app/Http/Controllers/*`. */
    case Path = 'path';

    /** The short name ends with this text. */
    case Suffix = 'suffix';

    /** A glob on the class it extends directly. */
    case Parent = 'parent';

    /** A glob on any class it extends, following parents the project declares. */
    case Extends = 'extends';

    /** A glob on an interface it implements directly. */
    case Implements = 'implements';

    /** A glob on a trait it uses directly. */
    case Uses = 'uses';

    /** A glob on an attribute on the declaration. */
    case Attribute = 'attribute';

    /** `class`, `interface`, `trait` or `enum`. */
    case Kind = 'kind';

    public const array KINDS = ['class', 'interface', 'trait', 'enum'];

    /**
     * What the matcher compares its patterns against.
     *
     * @return list<string>
     */
    public function subjects(ClassFacts $facts): array
    {
        return match ($this) {
            self::Namespace => [$facts->fqn],
            self::Path => [$facts->relativePath],
            self::Suffix => [$facts->shortName],
            self::Parent => $facts->parent === null ? [] : [$facts->parent],
            self::Extends => $facts->ancestors,
            self::Implements => $facts->interfaces,
            self::Uses => $facts->traits,
            self::Attribute => $facts->attributes,
            self::Kind => [$facts->kind],
        };
    }

    /**
     * Whether patterns are globs. A suffix and a kind are compared as written.
     */
    public function isGlob(): bool
    {
        return ! in_array($this, [self::Suffix, self::Kind], true);
    }
}
