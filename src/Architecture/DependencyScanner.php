<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

use Heyosseus\Sloppy\Ast\NodeHelper;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;

/**
 * The classes a declaration depends on, and where it first says so.
 *
 * Everything that names a class counts: what it extends and implements, the
 * traits it uses, its attributes, parameter, property and return types,
 * `new`, static calls and constants, `X::class`, `instanceof` and `catch`.
 * Docblocks do not, and neither do function and constant names, which the
 * parser also represents as names.
 */
final readonly class DependencyScanner
{
    /**
     * @return array<string, Name> Referenced class => its first mention.
     */
    public static function references(ClassLike $class): array
    {
        $self = NodeHelper::className($class);
        $found = [];

        foreach (NodeHelper::find($class, Name::class) as $name) {
            $parent = $name->getAttribute('parent');

            if (($parent instanceof FuncCall || $parent instanceof ConstFetch) && $parent->name === $name) {
                continue;
            }

            if ($name->isSpecialClassName()) {
                continue;
            }

            $fqn = $name->toString();

            if ($fqn === $self || isset($found[$fqn])) {
                continue;
            }

            $found[$fqn] = $name;
        }

        return $found;
    }
}
