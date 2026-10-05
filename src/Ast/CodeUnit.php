<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;

/**
 * One body of code a rule can judge on its own: a method, a named function,
 * a property hook, or a file's top-level code.
 *
 * Rules that measure "a method" -- its size, its nesting, its catches, its
 * guards -- would otherwise see only class methods, and a helper function or
 * a `routes/web.php` full of closures would never be looked at.
 */
final readonly class CodeUnit
{
    public const string METHOD = 'method';

    public const string FUNCTION = 'function';

    public const string HOOK = 'hook';

    public const string FILE = 'file';

    /** The name a file's top-level code goes by, as in a PHP stack trace. */
    public const string FILE_NAME = '{main}';

    /**
     * @param  self::METHOD|self::FUNCTION|self::HOOK|self::FILE  $kind
     * @param  string|null  $className  Short name of the declaring class -- `anonymous class` for one -- or null outside a class.
     * @param  string  $name  Method or function name, `$property::get` for a hook, {@see FILE_NAME} for a file.
     * @param  (FunctionLike&Node)|null  $node  The declaration; null for top-level code.
     * @param  list<Stmt>  $statements  The body; for top-level code, the file's statements.
     */
    public function __construct(
        public string $kind,
        public ?string $className,
        public string $name,
        public ?Node $node,
        public array $statements,
    ) {}

    /**
     * Every unit in a file: methods and property hooks of each declaration,
     * named functions wherever they are declared, and -- when asked for --
     * the file's top-level code.
     *
     * @return list<self>
     */
    public static function inFile(ParsedFile $file, bool $withTopLevel = false): array
    {
        $units = [];

        foreach ($file->classLikes() as $classLike) {
            $className = NodeHelper::shortName($classLike) ?? 'anonymous class';

            foreach ($classLike->stmts as $statement) {
                if ($statement instanceof ClassMethod) {
                    $units[] = new self(self::METHOD, $className, $statement->name->toString(), $statement, array_values($statement->stmts ?? []));

                    foreach ($statement->params as $param) {
                        $units = [...$units, ...self::hooksOf($className, $param)];
                    }
                }

                if ($statement instanceof Property) {
                    $units = [...$units, ...self::hooksOf($className, $statement)];
                }
            }
        }

        foreach (NodeHelper::find($file->ast, Function_::class) as $function) {
            $units[] = new self(self::FUNCTION, null, $function->name->toString(), $function, array_values($function->stmts));
        }

        // Declarations at the top of a file without a namespace are units of
        // their own; inside a namespace, findOwn() steps over them anyway.
        $topLevel = array_values(array_filter(
            $file->ast,
            static fn (Stmt $statement): bool => ! NodeHelper::isOwnScope($statement),
        ));

        if ($withTopLevel && $topLevel !== []) {
            $units[] = new self(self::FILE, null, self::FILE_NAME, null, $topLevel);
        }

        return $units;
    }

    /**
     * What a rule searches: the declaration itself, or the file's statements.
     *
     * Search it with {@see NodeHelper::findOwn()}, which leaves nested
     * classes and functions to their own units.
     *
     * @return Node|list<Stmt>
     */
    public function root(): Node|array
    {
        return $this->node ?? $this->statements;
    }

    /**
     * Whether there is a body to look at: abstract and interface methods have
     * none.
     */
    public function hasBody(): bool
    {
        if ($this->node instanceof ClassMethod) {
            return $this->node->stmts !== null;
        }

        if ($this->node instanceof PropertyHook) {
            return $this->node->body !== null;
        }

        return true;
    }

    /**
     * The owner a fingerprint is built on: `Order::store`, `helper`,
     * `Order::$total::get`, or `{main}`.
     */
    public function label(): string
    {
        return $this->className === null ? $this->name : $this->className.'::'.$this->name;
    }

    /**
     * The unit as a message names it: `Order::store()`, `helper()`, or
     * `top-level code`.
     */
    public function subject(): string
    {
        return $this->kind === self::FILE ? 'top-level code' : $this->label().'()';
    }

    /**
     * @return list<self>
     */
    private static function hooksOf(string $className, Property|Param $owner): array
    {
        if ($owner->hooks === []) {
            return [];
        }

        $property = $owner instanceof Property
            ? $owner->props[0]->name->toString()
            : ($owner->var instanceof Node\Expr\Variable && is_string($owner->var->name) ? $owner->var->name : 'property');

        $units = [];

        foreach ($owner->hooks as $hook) {
            $units[] = new self(
                self::HOOK,
                $className,
                '$'.$property.'::'.$hook->name->toString(),
                $hook,
                is_array($hook->body) ? array_values($hook->body) : [],
            );
        }

        return $units;
    }
}
