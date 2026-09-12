<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/**
 * Shared AST vocabulary.
 *
 * Every question more than one rule needs to ask lives here, so traversal logic
 * exists once and rules stay about the pattern they detect. If two rules start
 * duplicating a walk, the walk belongs in this class.
 */
final class NodeHelper
{
    /**
     * Statement types that introduce a level of control-flow nesting.
     *
     * Closures are deliberately absent: they are already an extraction
     * boundary, and Laravel code legitimately passes them to
     * `DB::transaction()`, `Route::group()` and collection pipelines. Counting
     * them would flag idiomatic code.
     *
     * @var list<class-string<Node>>
     */
    private const array NESTING_NODES = [
        If_::class,
        Foreach_::class,
        For_::class,
        While_::class,
        Do_::class,
        Switch_::class,
        TryCatch::class,
        Match_::class,
    ];

    /**
     * Sub-nodes replaced by a placeholder when hashing structure.
     *
     * The class a static call targets is the *subject* of the code, not what
     * the code does: `Order::where(...)->get()` and `Invoice::where(...)->get()`
     * are the same logic applied to two models, which is exactly the
     * duplication SL104 exists to find. Method and function names are kept, so
     * `->charge()` and `->refund()` never collapse into each other.
     *
     * @var array<class-string<Node>, list<string>>
     */
    private const array MASKED_SUBNODES = [
        StaticCall::class => ['class'],
        StaticPropertyFetch::class => ['class'],
        ClassConstFetch::class => ['class'],
        New_::class => ['class'],
        Instanceof_::class => ['class'],
    ];

    private static ?NodeFinder $finder = null;

    private static ?Standard $printer = null;

    private function __construct() {}

    /**
     * @template TNode of Node
     *
     * @param  Node|list<Node>  $subject
     * @param  class-string<TNode>  $type
     * @return list<TNode>
     */
    public static function find(Node|array $subject, string $type): array
    {
        /** @var list<TNode> $found */
        $found = self::finder()->findInstanceOf($subject, $type);

        return $found;
    }

    /**
     * @template TNode of Node
     *
     * @param  Node|list<Node>  $subject
     * @param  class-string<TNode>  $type
     * @return TNode|null
     */
    public static function findFirst(Node|array $subject, string $type): ?Node
    {
        return self::find($subject, $type)[0] ?? null;
    }

    /**
     * Print a node as a single normalised line.
     *
     * Used when comparing two expressions for equality: two conditions that
     * print identically are the same condition.
     */
    public static function printAny(Node $node): string
    {
        $printed = $node instanceof Expr
            ? self::printer()->prettyPrintExpr($node)
            : self::printer()->prettyPrint([$node]);

        return (string) preg_replace('/\s+/', ' ', trim($printed));
    }

    // -----------------------------------------------------------------
    // Names
    // -----------------------------------------------------------------

    /**
     * Fully qualified name of a declaration, or null for anonymous classes.
     */
    public static function className(ClassLike $class): ?string
    {
        // NameResolver writes this as a node property, not an attribute, and
        // leaves it uninitialised for anonymous classes.
        if (isset($class->namespacedName)) {
            return $class->namespacedName->toString();
        }

        return $class->name?->toString();
    }

    public static function shortName(ClassLike $class): ?string
    {
        return $class->name?->toString();
    }

    public static function kindOf(ClassLike $class): string
    {
        return match (true) {
            $class instanceof Interface_ => 'interface',
            $class instanceof Trait_ => 'trait',
            $class instanceof Enum_ => 'enum',
            default => 'class',
        };
    }

    /**
     * Name of the parent class, fully qualified where resolvable.
     */
    public static function parentName(ClassLike $class): ?string
    {
        if (! $class instanceof Class_) {
            return null;
        }

        return $class->extends?->toString();
    }

    /**
     * @return list<string>
     */
    public static function interfaceNames(ClassLike $class): array
    {
        $names = [];

        if ($class instanceof Class_) {
            foreach ($class->implements as $interface) {
                $names[] = $interface->toString();
            }
        }

        if ($class instanceof Interface_) {
            foreach ($class->extends as $interface) {
                $names[] = $interface->toString();
            }
        }

        return $names;
    }

    // -----------------------------------------------------------------
    // Laravel classification
    // -----------------------------------------------------------------

    /**
     * A class Laravel routes requests to.
     *
     * Matched on the parent class or on the conventional namespace, because
     * Laravel 11+ controllers frequently extend nothing at all.
     */
    public static function isController(ClassLike $class): bool
    {
        if (! $class instanceof Class_) {
            return false;
        }

        $name = self::className($class) ?? '';
        $parent = self::parentName($class) ?? '';

        if (str_contains($name, 'Http\\Controllers')) {
            return true;
        }

        if (str_ends_with($name, 'Controller') && ! str_ends_with($name, 'TestController')) {
            return true;
        }

        return str_ends_with($parent, 'Controller');
    }

    public static function isEloquentModel(ClassLike $class): bool
    {
        return $class instanceof Class_ && self::extendsEloquentModel(self::parentName($class));
    }

    /**
     * Whether a parent class name marks its child as an Eloquent model.
     *
     * Separate from {@see isEloquentModel()} so the project index can answer
     * the same question about a class it holds only a summary of.
     */
    public static function extendsEloquentModel(?string $parent): bool
    {
        if ($parent === null || $parent === '') {
            return false;
        }

        if (in_array($parent, [
            \Illuminate\Database\Eloquent\Model::class,
            \Illuminate\Foundation\Auth\User::class,
            \Illuminate\Database\Eloquent\Relations\Pivot::class,
        ], true)) {
            return true;
        }

        return str_ends_with($parent, '\Model') || $parent === 'Model';
    }

    /**
     * Whether a static call targets a keyword rather than a named class.
     *
     * `self::find()` on a helper class is not a database query, and this is
     * what tells the query rules to leave it alone.
     */
    public static function isSelfReference(?string $class): bool
    {
        return $class !== null && in_array(mb_strtolower($class), ['self', 'static', 'parent'], true);
    }

    public static function isFormRequest(ClassLike $class): bool
    {
        $parent = self::parentName($class) ?? '';

        return str_ends_with($parent, 'FormRequest');
    }

    /**
     * A class that exists to hold behaviour: service, action, manager, handler
     * and friends. Used by rules whose thresholds differ between "thin
     * delivery layer" and "this is where logic is supposed to live".
     */
    public static function isServiceClass(ClassLike $class): bool
    {
        if (! $class instanceof Class_) {
            return false;
        }

        $name = self::className($class) ?? '';

        foreach (['Service', 'Action', 'Manager', 'Handler', 'Repository', 'UseCase', 'Interactor'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        foreach (['\Services\\', '\Actions\\', '\Domain\\', '\Application\\'] as $segment) {
            if (str_contains($name, $segment)) {
                return true;
            }
        }

        return false;
    }

    public static function isQueueable(ClassLike $class): bool
    {
        $name = self::className($class) ?? '';

        return str_contains($name, '\Jobs\\') || str_ends_with($name, 'Job');
    }

    public static function isMiddleware(ClassLike $class): bool
    {
        return str_contains(self::className($class) ?? '', '\Middleware\\');
    }

    // -----------------------------------------------------------------
    // Members
    // -----------------------------------------------------------------

    /**
     * @return list<ClassMethod>
     */
    public static function methods(ClassLike $class): array
    {
        $methods = [];

        foreach ($class->stmts as $statement) {
            if ($statement instanceof ClassMethod) {
                $methods[] = $statement;
            }
        }

        return $methods;
    }

    /**
     * @return list<ClassMethod>
     */
    public static function publicMethods(ClassLike $class): array
    {
        return array_values(array_filter(
            self::methods($class),
            static fn (ClassMethod $method): bool => $method->isPublic(),
        ));
    }

    public static function constructor(ClassLike $class): ?ClassMethod
    {
        foreach (self::methods($class) as $method) {
            if (strcasecmp($method->name->toString(), '__construct') === 0) {
                return $method;
            }
        }

        return null;
    }

    /**
     * @return list<Param>
     */
    public static function constructorParams(ClassLike $class): array
    {
        $constructor = self::constructor($class);

        return $constructor instanceof ClassMethod ? array_values($constructor->params) : [];
    }

    /**
     * Injected collaborators: constructor parameters typed as a class.
     *
     * Scalars, arrays and variadics are not dependencies -- a constructor
     * taking `string $currency` is configuration, not coupling.
     */
    public static function countDependencies(ClassLike $class): int
    {
        $count = 0;

        foreach (self::constructorParams($class) as $param) {
            if (self::isObjectType($param)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    public static function dependencyNames(ClassLike $class): array
    {
        $names = [];

        foreach (self::constructorParams($class) as $param) {
            if (! self::isObjectType($param)) {
                continue;
            }

            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $names[] = $param->var->name;
            }
        }

        return $names;
    }

    public static function isObjectType(Param $param): bool
    {
        $type = $param->type;

        if (! $type instanceof Node || $param->variadic) {
            return false;
        }

        $printed = self::typeToString($type);

        if ($printed === null) {
            return false;
        }

        $scalars = ['int', 'float', 'string', 'bool', 'array', 'mixed', 'callable', 'iterable', 'object', 'null', 'false', 'true'];

        foreach (explode('|', str_replace(['?', '&'], ['', '|'], $printed)) as $part) {
            if (! in_array(mb_strtolower(trim($part)), $scalars, true) && trim($part) !== '') {
                return true;
            }
        }

        return false;
    }

    public static function typeToString(?Node $type): ?string
    {
        if (! $type instanceof Node) {
            return null;
        }

        $name = self::typeName($type);

        return $name === '' ? null : $name;
    }

    /**
     * @return list<string>
     */
    public static function propertyNames(ClassLike $class): array
    {
        $names = [];

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Property) {
                continue;
            }

            foreach ($statement->props as $property) {
                $names[] = $property->name->toString();
            }
        }

        return $names;
    }

    // -----------------------------------------------------------------
    // Metrics
    // -----------------------------------------------------------------

    /**
     * Physical lines a node spans.
     */
    public static function lineSpan(Node $node): int
    {
        $start = $node->getStartLine();
        $end = $node->getEndLine();

        if ($start < 1 || $end < $start) {
            return 0;
        }

        return $end - $start + 1;
    }

    /**
     * Executable statements inside a node, ignoring structural noise.
     */
    public static function countStatements(Node $node): int
    {
        $count = 0;

        foreach (self::find($node, Stmt::class) as $statement) {
            if ($statement instanceof Nop) {
                continue;
            }

            if ($statement instanceof ClassLike || $statement instanceof ClassMethod) {
                continue;
            }

            if ($statement instanceof Stmt\Use_ || $statement instanceof Stmt\Declare_ || $statement instanceof Stmt\Namespace_) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * McCabe cyclomatic complexity: one, plus one per decision point.
     */
    public static function cyclomaticComplexity(Node $node): int
    {
        $complexity = 1;

        foreach (self::find($node, Node::class) as $child) {
            $complexity += match (true) {
                $child instanceof If_,
                $child instanceof ElseIf_,
                $child instanceof Foreach_,
                $child instanceof For_,
                $child instanceof While_,
                $child instanceof Do_,
                $child instanceof Catch_,
                $child instanceof Ternary,
                $child instanceof BinaryOp\BooleanAnd,
                $child instanceof BinaryOp\BooleanOr,
                $child instanceof BinaryOp\LogicalAnd,
                $child instanceof BinaryOp\LogicalOr => 1,
                $child instanceof Case_ => $child->cond instanceof Expr ? 1 : 0,
                $child instanceof Match_ => count($child->arms),
                default => 0,
            };
        }

        return $complexity;
    }

    /**
     * Deepest control-flow nesting inside a node.
     */
    public static function maxNestingDepth(Node $node): int
    {
        return self::depthOf($node, 0);
    }

    /**
     * The innermost nesting-introducing node, so a rule can point at the line
     * that actually hurts rather than at the top of the method.
     */
    public static function deepestNestedNode(Node $node): ?Node
    {
        $deepest = null;
        $best = 0;

        self::walkDepths($node, 0, $deepest, $best);

        return $deepest;
    }

    /**
     * Every method/function/static call made inside a node.
     */
    public static function countCalls(Node $node): int
    {
        return count(self::find($node, MethodCall::class))
            + count(self::find($node, StaticCall::class))
            + count(self::find($node, FuncCall::class))
            + count(self::find($node, NullsafeMethodCall::class));
    }

    /**
     * How many distinct collaborators a node talks to.
     *
     * A method that calls twelve different objects is doing more than one job,
     * regardless of how many lines it takes to do it.
     */
    public static function countDistinctCallTargets(Node $node): int
    {
        $targets = [];

        foreach (self::find($node, MethodCall::class) as $call) {
            $targets[self::printAny($call->var)] = true;
        }

        foreach (self::find($node, StaticCall::class) as $call) {
            if ($call->class instanceof Name) {
                $targets[$call->class->toString()] = true;
            }
        }

        return count($targets);
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    /**
     * Walk up the parent chain, closest ancestor first.
     *
     * @return list<Node>
     */
    public static function ancestors(Node $node): array
    {
        $ancestors = [];
        $current = $node->getAttribute('parent');

        while ($current instanceof Node) {
            $ancestors[] = $current;
            $current = $current->getAttribute('parent');
        }

        return $ancestors;
    }

    /**
     * @template TNode of Node
     *
     * @param  class-string<TNode>  $type
     * @return TNode|null
     */
    public static function closestAncestor(Node $node, string $type): ?Node
    {
        foreach (self::ancestors($node) as $ancestor) {
            if ($ancestor instanceof $type) {
                return $ancestor;
            }
        }

        return null;
    }

    /**
     * The loop a node sits inside, if any. Stops at closure boundaries only
     * for arrow functions, since a closure body inside a loop still executes
     * once per iteration.
     */
    public static function enclosingLoop(Node $node): ?Node
    {
        foreach (self::ancestors($node) as $ancestor) {
            if ($ancestor instanceof Foreach_ || $ancestor instanceof For_ || $ancestor instanceof While_ || $ancestor instanceof Do_) {
                return $ancestor;
            }
        }

        return null;
    }

    public static function isInsideLoop(Node $node): bool
    {
        return self::enclosingLoop($node) instanceof Node;
    }

    public static function enclosingMethod(Node $node): ?ClassMethod
    {
        return self::closestAncestor($node, ClassMethod::class);
    }

    // -----------------------------------------------------------------
    // Calls
    // -----------------------------------------------------------------

    /**
     * Class name of a static call, fully qualified where the parser could
     * resolve it.
     */
    public static function staticCallClass(StaticCall $call): ?string
    {
        return $call->class instanceof Name ? $call->class->toString() : null;
    }

    public static function callName(Node $node): ?string
    {
        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall) {
            return $node->name instanceof Identifier ? $node->name->toString() : null;
        }

        if ($node instanceof FuncCall) {
            return $node->name instanceof Name ? $node->name->toString() : null;
        }

        return null;
    }

    /**
     * Walk a fluent chain down to whatever it started from.
     *
     * `Order::where(...)->with(...)->get()` returns the `Order::where(...)`
     * static call, so a rule can ask what the chain is rooted in.
     */
    public static function chainRoot(Node $node): Node
    {
        $current = $node;

        while (true) {
            if ($current instanceof MethodCall || $current instanceof NullsafeMethodCall) {
                $current = $current->var;

                continue;
            }

            if ($current instanceof PropertyFetch || $current instanceof NullsafePropertyFetch) {
                $current = $current->var;

                continue;
            }

            return $current;
        }
    }

    /**
     * Climb to the end of a fluent chain.
     *
     * Given the `DB::table(...)` inside `DB::table(...)->where(...)->get()`,
     * this returns the outer `->get()` call -- the node that knows what the
     * whole chain actually does.
     */
    public static function outermostChain(Node $node): Node
    {
        $current = $node;

        while (true) {
            $parent = $current->getAttribute('parent');

            if (($parent instanceof MethodCall || $parent instanceof NullsafeMethodCall) && $parent->var === $current) {
                $current = $parent;

                continue;
            }

            return $current;
        }
    }

    /**
     * Method names used anywhere in a fluent chain.
     *
     * @return list<string>
     */
    public static function chainMethodNames(Node $node): array
    {
        $names = [];
        $current = $node;

        while ($current instanceof MethodCall || $current instanceof NullsafeMethodCall || $current instanceof StaticCall) {
            $name = self::callName($current);

            if ($name !== null) {
                $names[] = $name;
            }

            if ($current instanceof StaticCall) {
                break;
            }

            $current = $current->var;
        }

        return array_reverse($names);
    }

    /**
     * @param  list<string>  $candidates
     */
    public static function isNameOneOf(?string $name, array $candidates): bool
    {
        if ($name === null) {
            return false;
        }

        $lower = mb_strtolower($name);

        foreach ($candidates as $candidate) {
            if (mb_strtolower($candidate) === $lower) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a static call targets one of the given facades, matched on the
     * short class name so both `Http::` and `\Illuminate\Support\Facades\Http::`
     * are recognised.
     *
     * @param  list<string>  $facades
     */
    public static function isFacadeCall(StaticCall $call, array $facades): bool
    {
        $class = self::staticCallClass($call);

        if ($class === null) {
            return false;
        }

        $short = self::baseName($class);

        return in_array($short, $facades, true);
    }

    public static function baseName(string $fqn): string
    {
        $position = mb_strrpos($fqn, '\\');

        return $position === false ? $fqn : mb_substr($fqn, $position + 1);
    }

    /**
     * Variables assigned anywhere in a node, mapped to the expression that
     * produced them. Later assignments win, which is what a rule reasoning
     * about "where did this come from" wants.
     *
     * @return array<string, Expr>
     */
    public static function assignedVariables(Node $node): array
    {
        $assignments = [];

        foreach (self::find($node, Assign::class) as $assign) {
            if ($assign->var instanceof Variable && is_string($assign->var->name)) {
                $assignments[$assign->var->name] = $assign->expr;
            }
        }

        return $assignments;
    }

    /**
     * Whether a node contains anything that could reach a member dynamically,
     * which makes "this looks unused" claims unsafe.
     */
    public static function hasDynamicAccess(Node $node): bool
    {
        foreach (self::find($node, FuncCall::class) as $call) {
            if (self::isNameOneOf(self::callName($call), ['compact', 'extract', 'get_object_vars', 'call_user_func', 'call_user_func_array', 'method_exists', 'property_exists', 'func_get_args'])) {
                return true;
            }
        }

        foreach (self::find($node, PropertyFetch::class) as $fetch) {
            if (! $fetch->name instanceof Identifier) {
                return true;
            }
        }

        foreach (self::find($node, MethodCall::class) as $call) {
            if (! $call->name instanceof Identifier) {
                return true;
            }
        }

        return false;
    }

    /**
     * A structural fingerprint that ignores names of local variables and the
     * value of literals, but keeps call names and control flow.
     *
     * Two methods with the same fingerprint do the same thing to different
     * variables -- which is what duplicate logic looks like.
     */
    public static function structuralHash(Node $node): string
    {
        return hash('sha256', self::structure($node));
    }

    /**
     * Both views of a node at once: the token stream, and the values masking
     * removed from it.
     *
     * One traversal, because the two lists are compared positionally and an
     * ordering disagreement between them would read as a divergence in the
     * code rather than a bug in this class.
     */
    public static function signature(Node $node): NodeSignature
    {
        $tokens = [];
        $masked = [];

        self::describe($node, $tokens, $masked);

        return new NodeSignature($tokens, $masked);
    }

    public static function structure(Node $node): string
    {
        return implode('', self::signature($node)->tokens);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param  list<string>  $parts
     * @param  list<string>  $masked
     */
    private static function describe(Node $node, array &$parts, array &$masked): void
    {
        $parts[] = match (true) {
            $node instanceof Variable => 'V',
            $node instanceof Scalar => 'L',
            $node instanceof Identifier => 'i:'.$node->toString(),
            $node instanceof Name => 'n:'.self::baseName($node->toString()),
            default => self::baseName($node::class),
        };

        if ($node instanceof Variable && is_string($node->name)) {
            $masked[] = 'var:'.$node->name;
        } elseif ($node instanceof Scalar) {
            $masked[] = 'lit:'.self::scalarValue($node);
        }

        $parts[] = '(';

        $maskedSubnodes = self::MASKED_SUBNODES[$node::class] ?? [];

        foreach ($node->getSubNodeNames() as $name) {
            /** @var mixed $value */
            $value = $node->{$name};

            if (in_array($name, $maskedSubnodes, true)) {
                $parts[] = 'C';
                $masked[] = 'class:'.($value instanceof Name ? self::baseName($value->toString()) : '?');

                continue;
            }

            if ($value instanceof Node) {
                self::describe($value, $parts, $masked);

                continue;
            }

            if (is_array($value)) {
                /** @var mixed $item */
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        self::describe($item, $parts, $masked);
                    }
                }
            }
        }

        $parts[] = ')';
    }

    /**
     * A literal's value as text, for comparing two blocks that hash the same.
     *
     * Interpolated strings and magic constants fall back to their node name:
     * their value is not a constant, so there is nothing to compare.
     */
    private static function scalarValue(Scalar $scalar): string
    {
        return match (true) {
            $scalar instanceof String_ => $scalar->value,
            $scalar instanceof Int_ => (string) $scalar->value,
            $scalar instanceof Float_ => (string) $scalar->value,
            default => self::baseName($scalar::class),
        };
    }

    private static function walkDepths(Node $node, int $depth, ?Node &$deepest, int &$best): void
    {
        foreach ($node->getSubNodeNames() as $name) {
            /** @var mixed $value */
            $value = $node->{$name};

            /** @var list<mixed> $children */
            $children = is_array($value) ? $value : [$value];

            /** @var mixed $child */
            foreach ($children as $child) {
                if (! $child instanceof Node) {
                    continue;
                }

                $childDepth = $depth + (self::introducesNesting($child) ? 1 : 0);

                if ($childDepth > $best) {
                    $best = $childDepth;
                    $deepest = $child;
                }

                self::walkDepths($child, $childDepth, $deepest, $best);
            }
        }
    }

    private static function depthOf(Node $node, int $depth): int
    {
        $max = $depth;

        foreach ($node->getSubNodeNames() as $name) {
            /** @var mixed $value */
            $value = $node->{$name};

            /** @var list<mixed> $children */
            $children = is_array($value) ? $value : [$value];

            /** @var mixed $child */
            foreach ($children as $child) {
                if (! $child instanceof Node) {
                    continue;
                }

                $increment = self::introducesNesting($child) ? 1 : 0;
                $max = max($max, self::depthOf($child, $depth + $increment));
            }
        }

        return $max;
    }

    private static function introducesNesting(Node $node): bool
    {
        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            return false;
        }

        foreach (self::NESTING_NODES as $type) {
            if ($node instanceof $type) {
                return true;
            }
        }

        return false;
    }

    private static function typeName(Node $type): string
    {
        if ($type instanceof Name) {
            return $type->toString();
        }

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        if ($type instanceof Node\NullableType) {
            return '?'.self::typeName($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            return implode('|', array_map(self::typeName(...), $type->types));
        }

        return '';
    }

    private static function finder(): NodeFinder
    {
        return self::$finder ??= new NodeFinder;
    }

    private static function printer(): Standard
    {
        return self::$printer ??= new Standard;
    }
}
