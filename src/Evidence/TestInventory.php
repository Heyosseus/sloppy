<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Evidence;

use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ParsedFile;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * The tests in one file, PHPUnit or Pest, and what each one checks.
 *
 * Assertions are counted by call, not by outcome: `$this->assert*`,
 * `self::assert*`, `$response->assert*`, `Http::assertSent`, every matcher on
 * an `expect()` chain, `expectException*` and Mockery expectations. A call to
 * a helper defined in the same file counts as the assertions the helper makes,
 * so moving three assertions into `assertInvoiceIsPaid()` is not mistaken for
 * deleting them.
 */
final class TestInventory
{
    private const array SKIPS = ['marktestskipped', 'marktestincomplete'];

    private const array PEST_SKIPS = ['skip', 'todo'];

    private const array EXPECTATIONS = ['shouldreceive', 'shouldhavereceived', 'shouldnothavereceived'];

    /**
     * Helpers in scope for the body being counted, by lower-cased name.
     *
     * @var array<string, int>
     */
    private array $helpers = [];

    /**
     * @return array<string, TestBody>
     */
    public static function of(ParsedFile $file): array
    {
        return (new self)->collect($file);
    }

    /**
     * @return array<string, TestBody>
     */
    private function collect(ParsedFile $file): array
    {
        $tests = [];

        foreach ($file->classLikes() as $class) {
            $className = NodeHelper::shortName($class) ?? 'class';
            $methods = NodeHelper::methods($class);

            $this->helpers = [];

            foreach ($methods as $method) {
                if (! $this->isTestMethod($method) && $method->stmts !== null) {
                    $this->helpers[$method->name->toLowerString()] = $this->assertionsIn($method->stmts);
                }
            }

            foreach ($methods as $method) {
                if ($this->isTestMethod($method)) {
                    $name = $className.'::'.$method->name->toString();
                    $tests[$name] = $this->body($name, $method, $method->stmts ?? [], false);
                }
            }
        }

        $this->helpers = [];

        foreach (NodeHelper::find($file->ast, Function_::class) as $function) {
            $this->helpers[$function->name->toLowerString()] = $this->assertionsIn($function->stmts);
        }

        foreach (NodeHelper::find($file->ast, FuncCall::class) as $call) {
            $name = $this->pestName($call);

            if ($name === null) {
                continue;
            }

            $closure = $call->args[1] ?? null;
            $closure = $closure instanceof Arg ? $closure->value : null;

            $statements = match (true) {
                $closure instanceof Closure => $closure->stmts,
                $closure instanceof ArrowFunction => [new Stmt\Expression($closure->expr)],
                default => [],
            };

            $chained = array_map(mb_strtolower(...), NodeHelper::chainMethodNames(NodeHelper::outermostChain($call)));

            $tests[$name] = $this->body($name, $call, $statements, array_intersect($chained, self::PEST_SKIPS) !== []);
        }

        return $tests;
    }

    /**
     * @param  array<Stmt>  $statements
     */
    private function body(string $name, Node $at, array $statements, bool $skippedByChain): TestBody
    {
        $printed = implode("\n", array_map(NodeHelper::printAny(...), $statements));

        return new TestBody(
            name: $name,
            line: max(1, $at->getStartLine()),
            assertions: $this->assertionsIn($statements),
            skipped: $skippedByChain || $this->callsAny($statements, self::SKIPS),
            trivial: $this->trivialIn($statements),
            hash: md5($printed),
        );
    }

    private function isTestMethod(ClassMethod $method): bool
    {
        if ($method->stmts === null || ! $method->isPublic()) {
            return false;
        }

        if (str_starts_with($method->name->toString(), 'test')) {
            return true;
        }

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (NodeHelper::baseName($attribute->name->toString()) === 'Test') {
                    return true;
                }
            }
        }

        return str_contains((string) $method->getDocComment()?->getText(), '@test');
    }

    /**
     * The name of a Pest `it()` or `test()` call, prefixed by any `describe()`
     * blocks around it, or null when the call is not a test.
     */
    private function pestName(FuncCall $call): ?string
    {
        $function = $this->functionName($call);

        if (! in_array($function, ['it', 'test'], true)) {
            return null;
        }

        $description = $this->firstString($call);

        if ($description === null) {
            return null;
        }

        $name = $function === 'it' ? 'it '.$description : $description;

        foreach (NodeHelper::ancestors($call) as $ancestor) {
            if ($ancestor instanceof FuncCall && $this->functionName($ancestor) === 'describe') {
                $name = ($this->firstString($ancestor) ?? '').' > '.$name;
            }
        }

        return $name;
    }

    private function functionName(FuncCall $call): ?string
    {
        return $call->name instanceof Name ? mb_strtolower($call->name->getLast()) : null;
    }

    private function firstString(FuncCall $call): ?string
    {
        $argument = $call->args[0] ?? null;

        return $argument instanceof Arg && $argument->value instanceof String_ ? $argument->value->value : null;
    }

    /**
     * @param  array<Stmt>  $statements
     */
    private function assertionsIn(array $statements): int
    {
        $count = 0;

        foreach ($this->calls($statements) as $call) {
            $name = mb_strtolower(NodeHelper::callName($call) ?? '');

            if ($name === '') {
                continue;
            }

            if ($this->isLocalHelperCall($call) && isset($this->helpers[$name])) {
                $count += $this->helpers[$name];

                continue;
            }

            if (str_starts_with($name, 'assert')
                || str_starts_with($name, 'expectexception')
                || in_array($name, self::EXPECTATIONS, true)
                || $this->isExpectMatcher($call)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Assertions that cannot fail: `assertTrue(true)`, `assertSame($a, $a)`,
     * `expect(true)->toBeTrue()`, `addToAssertionCount()`.
     *
     * @param  array<Stmt>  $statements
     */
    private function trivialIn(array $statements): int
    {
        $count = 0;

        foreach ($this->calls($statements) as $call) {
            $name = mb_strtolower(NodeHelper::callName($call) ?? '');
            $arguments = $this->argumentValues($call);

            $trivial = match (true) {
                $name === 'addtoassertioncount' => true,
                $name === 'asserttrue' => $this->isConstant($arguments[0] ?? null, 'true'),
                $name === 'assertfalse' => $this->isConstant($arguments[0] ?? null, 'false'),
                $name === 'assertnull' => $this->isConstant($arguments[0] ?? null, 'null'),
                in_array($name, ['assertsame', 'assertequals'], true) => $this->samePrinted($arguments[0] ?? null, $arguments[1] ?? null),
                default => $this->isTrivialExpectation($call, $name, $arguments),
            };

            if ($trivial) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<Expr>  $arguments
     */
    private function isTrivialExpectation(Node $call, string $name, array $arguments): bool
    {
        $subject = $this->expectSubject($call);

        if (! $subject instanceof Expr || ! $subject instanceof ConstFetch && ! $subject instanceof Scalar) {
            return false;
        }

        return match ($name) {
            'tobetrue' => $this->isConstant($subject, 'true'),
            'tobefalse' => $this->isConstant($subject, 'false'),
            'tobenull' => $this->isConstant($subject, 'null'),
            'tobe', 'toequal' => $this->samePrinted($subject, $arguments[0] ?? null),
            default => false,
        };
    }

    private function isExpectMatcher(Node $call): bool
    {
        return $call instanceof MethodCall
            && preg_match('/^to[A-Z]/', NodeHelper::callName($call) ?? '') === 1
            && $this->expectSubject($call) instanceof Expr;
    }

    /**
     * What an `expect()` chain is asserting about, when the call is a matcher
     * on one.
     */
    private function expectSubject(Node $call): ?Expr
    {
        if (! $call instanceof MethodCall) {
            return null;
        }

        $root = NodeHelper::chainRoot($call);

        if (! $root instanceof FuncCall || $this->functionName($root) !== 'expect') {
            return null;
        }

        $argument = $root->args[0] ?? null;

        return $argument instanceof Arg ? $argument->value : null;
    }

    private function isLocalHelperCall(Node $call): bool
    {
        if ($call instanceof FuncCall) {
            return true;
        }

        if ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) {
            return $call->var instanceof Variable && $call->var->name === 'this';
        }

        return $call instanceof StaticCall
            && $call->class instanceof Name
            && in_array($call->class->toLowerString(), ['self', 'static'], true);
    }

    /**
     * @param  array<Stmt>  $statements
     * @param  list<string>  $names
     */
    private function callsAny(array $statements, array $names): bool
    {
        foreach ($this->calls($statements) as $call) {
            if (in_array(mb_strtolower(NodeHelper::callName($call) ?? ''), $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<Stmt>  $statements
     * @return list<Node>
     */
    private function calls(array $statements): array
    {
        return array_values((new NodeFinder)->find($statements, static fn (Node $node): bool => $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof StaticCall
            || $node instanceof FuncCall));
    }

    /**
     * @return list<Expr>
     */
    private function argumentValues(Node $call): array
    {
        if (! $call instanceof MethodCall && ! $call instanceof NullsafeMethodCall && ! $call instanceof StaticCall && ! $call instanceof FuncCall) {
            return [];
        }

        $values = [];

        foreach ($call->args as $argument) {
            if ($argument instanceof Arg) {
                $values[] = $argument->value;
            }
        }

        return $values;
    }

    private function isConstant(?Expr $expression, string $value): bool
    {
        return $expression instanceof ConstFetch && $expression->name->toLowerString() === $value;
    }

    private function samePrinted(?Expr $left, ?Expr $right): bool
    {
        return $left instanceof Expr && $right instanceof Expr && NodeHelper::printAny($left) === NodeHelper::printAny($right);
    }
}
