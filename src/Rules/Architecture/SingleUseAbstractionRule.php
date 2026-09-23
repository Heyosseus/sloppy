<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Architecture;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Nop;

/**
 * SL303 -- an interface or abstract class with one implementation and one
 * caller.
 *
 * Advisory by design. An interface written ahead of a second implementation is
 * a bet on the future, and sometimes the right one; this rule only points out
 * that the bet has not paid off yet in the code it can see.
 */
final class SingleUseAbstractionRule extends BaseRule
{
    public function id(): string
    {
        return 'SL303';
    }

    public function name(): string
    {
        return 'Single-Use Abstraction';
    }

    public function description(): string
    {
        return 'Flags small interfaces and abstract classes that have exactly one implementation and at most one calling file.';
    }

    public function explanation(): string
    {
        return 'An interface earns its keep by letting callers not care which implementation they got. With one '
            .'implementation and one caller there is no choice being hidden, so the interface mostly duplicates the '
            .'class signature and doubles the edits every change needs. This is advice, not a defect: keep it if a '
            .'second implementation or a test double is genuinely on the way.';
    }

    public function category(): Category
    {
        return Category::Architecture;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Low;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $maxMethods = max(1, $this->intOption('max_methods', 3));
        $maxUsages = max(0, $this->intOption('max_usages', 1));
        $skipInflated = $this->boolOption('skip_layer_stacks', true);
        $minLayers = max(2, $this->intOption('layer_stack_depth', 3));

        $stacks = $skipInflated ? LayerStack::group($context->index) : [];

        foreach ($context->classLikes() as $classLike) {
            $isInterface = $classLike instanceof Interface_;
            $isAbstract = $classLike instanceof Class_ && $classLike->isAbstract();

            if (! $isInterface && ! $isAbstract) {
                continue;
            }

            // An abstraction that declares nothing has no signature to
            // duplicate, so the cost this rule measures is not there. It is an
            // attachment point -- Laravel's scaffolded Controller, a marker
            // interface -- and flagging it teaches people to ignore reports.
            if (self::declaresNothing($classLike)) {
                continue;
            }

            // An interface or an abstract class always has a name -- only a
            // `new class` expression does not, and that is neither -- so the
            // empty fallback below simply finds no summary.
            $fqn = NodeHelper::className($classLike) ?? '';
            $name = NodeHelper::shortName($classLike) ?? '';

            $summary = $context->index->class($fqn);

            if (! $summary instanceof \Heyosseus\Sloppy\Ast\ClassSummary || $summary->methodCount > $maxMethods) {
                continue;
            }

            $implementations = $context->index->implementationsOf($fqn);

            if (count($implementations) !== 1) {
                continue;
            }

            $usages = $context->index->usageCount($fqn);

            if ($usages > $maxUsages) {
                continue;
            }

            // A deep layer stack is SL301's finding; reporting both would say
            // the same thing twice.
            if ($this->belongsToInflatedStack($stacks, $fqn, $minLayers)) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $classLike,
                message: sprintf(
                    '%s %s declares %d method(s), is implemented only by %s, and is referenced by %s.',
                    $isInterface ? 'Interface' : 'Abstract class',
                    $name,
                    $summary->methodCount,
                    NodeHelper::baseName($implementations[0]),
                    $usages === 0 ? 'nothing in the analysed paths' : 'one file',
                ),
                suggestion: sprintf(
                    'Depending on %s directly would remove a file without removing a capability. Worth keeping if '
                    .'a second implementation, a fake for tests, or a package boundary is actually planned.',
                    NodeHelper::baseName($implementations[0]),
                ),
                confidence: $this->confidenceFrom(55, [
                    $usages === 0,
                    $summary->methodCount <= 1,
                ], 8, 72),
                fingerprint: $name,
                metrics: [
                    'methods' => $summary->methodCount,
                    'implementation' => NodeHelper::baseName($implementations[0]),
                    'usages' => $usages,
                ],
            );
        }
    }

    /**
     * True when the body holds nothing but comments -- no method, property,
     * constant or trait. A comment-only body parses to a `Nop`.
     */
    private static function declaresNothing(ClassLike $classLike): bool
    {
        foreach ($classLike->stmts as $statement) {
            if (! $statement instanceof Nop) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, LayerStack>  $stacks
     */
    private function belongsToInflatedStack(array $stacks, string $fqn, int $minLayers): bool
    {
        foreach ($stacks as $stack) {
            if ($stack->member($fqn) !== null && $stack->depth() >= $minLayers) {
                return true;
            }
        }

        return false;
    }
}
