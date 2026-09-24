<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;

/**
 * SL105 -- private methods nothing appears to call.
 *
 * Private visibility is what makes this answerable: every possible caller is in
 * the same file. The rule still steps back from anything that could reach a
 * method indirectly, because a wrong "delete this" is far more expensive than a
 * missed one.
 */
final class DeadPrivateMethodRule extends BaseRule
{
    /**
     * Members PHP or a framework may call without any call site in the source.
     *
     * @var list<string>
     */
    private const array FRAMEWORK_HOOKS = [
        'boot', 'booted', 'booting', 'register', 'setUp', 'tearDown', 'handle', 'configure',
    ];

    public function id(): string
    {
        return 'SL105';
    }

    public function name(): string
    {
        return 'Dead Private Method';
    }

    public function description(): string
    {
        return 'Flags private methods with no visible caller anywhere in the declaring file.';
    }

    public function explanation(): string
    {
        return 'A private method can only be called from within its own class, so an unreferenced one is very '
            .'likely dead: it still has to be read, understood and kept compiling. Calls from traits the class '
            .'uses count as callers. Anything that could be reached '
            .'indirectly -- magic methods, dynamic calls, a name appearing as a string, attributes -- is skipped '
            .'rather than guessed at.';
    }

    public function category(): Category
    {
        return Category::DeadCode;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        foreach ($context->classLikes() as $classLike) {
            // Traits are excluded: a private method in a trait can be called by
            // whichever class composes it, which is not visible from here.
            if (! $classLike instanceof Class_ && ! $classLike instanceof Enum_) {
                continue;
            }

            $className = NodeHelper::shortName($classLike);

            if ($className === null) {
                continue;
            }

            $methods = NodeHelper::methods($classLike);

            if ($this->hasMagicDispatch($methods)) {
                continue;
            }

            if (NodeHelper::hasDynamicAccess($classLike)) {
                continue;
            }

            $traitCalls = $this->traitCalls($context, NodeHelper::className($classLike));

            if ($traitCalls === null) {
                continue;
            }

            $called = $this->calledNames($classLike) + $traitCalls;
            $literals = $this->stringLiterals($classLike);

            foreach ($methods as $method) {
                if (! $method->isPrivate()) {
                    continue;
                }

                $name = $method->name->toString();

                if (str_starts_with($name, '__')) {
                    continue;
                }

                if ($method->attrGroups !== []) {
                    continue;
                }

                if (NodeHelper::isNameOneOf($name, self::FRAMEWORK_HOOKS)) {
                    continue;
                }

                if (isset($called[mb_strtolower($name)]) || isset($literals[mb_strtolower($name)])) {
                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: $method,
                    message: sprintf(
                        '%s::%s() is private and has no caller in %s.',
                        $className,
                        $name,
                        $context->relativePath(),
                    ),
                    suggestion: 'Delete the method if it is genuinely unused. If it is reached through a callback, '
                        .'a route or a framework hook, make that call site explicit so both readers and tools can '
                        .'see it.',
                    confidence: 88,
                    fingerprint: $className.'::'.$name,
                    metrics: [
                        'lines' => NodeHelper::lineSpan($method),
                    ],
                );
            }
        }
    }

    /**
     * @param  list<ClassMethod>  $methods
     */
    private function hasMagicDispatch(array $methods): bool
    {
        foreach ($methods as $method) {
            if (NodeHelper::isNameOneOf($method->name->toString(), ['__call', '__callStatic', '__get', '__set'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every method name invoked anywhere in the class, regardless of receiver.
     *
     * Matching on name alone rather than on `$this` is deliberate: a method
     * passed as `[$this, 'name']` or called on a clone should not be reported.
     *
     * @return array<string, true>
     */
    private function calledNames(Class_|Enum_ $classLike): array
    {
        $names = [];

        foreach ([MethodCall::class, NullsafeMethodCall::class, StaticCall::class] as $type) {
            foreach (NodeHelper::find($classLike, $type) as $call) {
                $name = NodeHelper::callName($call);

                if ($name !== null) {
                    $names[mb_strtolower($name)] = true;
                }
            }
        }

        return $names;
    }

    /**
     * Method names called, or named in a string, by the traits the class uses,
     * directly or through other traits. A trait can call a private method of
     * the class that composes it, often one it declares `abstract private`.
     * Null when a trait calls dynamically and so could call anything.
     *
     * @return array<string, true>|null
     */
    private function traitCalls(AnalysisContext $context, ?string $fqn): ?array
    {
        $names = [];

        foreach ($fqn === null ? [] : $context->index->traitsOf($fqn) as $trait) {
            if ($trait->hasDynamicAccess) {
                return null;
            }

            foreach ($trait->calledNames as $name) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * @return array<string, true>
     */
    private function stringLiterals(Class_|Enum_ $classLike): array
    {
        $literals = [];

        foreach (NodeHelper::find($classLike, String_::class) as $string) {
            $literals[mb_strtolower($string->value)] = true;
        }

        return $literals;
    }
}
