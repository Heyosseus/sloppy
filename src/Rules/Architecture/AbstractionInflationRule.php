<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Architecture;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Rules\BaseRule;

/**
 * SL301 -- several layers wrapping one concept, where the layers are not
 * earning their place.
 *
 * This rule has an opinion about evidence, not about architecture. Repositories
 * and interfaces are not mistakes; a stack of them where each layer is tiny,
 * has one implementation and one caller is indirection that costs more to read
 * than it saves. The wording of every finding says "may be" on purpose, because
 * only the team knows what is coming next.
 */
final class AbstractionInflationRule extends BaseRule
{
    /**
     * Base classes a framework expects one subclass of per concept. A class
     * extending one, directly or through a project base class, is a
     * convention the team adopted, not a layer it added, so it neither counts
     * towards a stack nor gets reported.
     *
     * @var list<string>
     */
    public const array CONVENTION_BASES = [
        \Illuminate\Database\Eloquent\Factories\Factory::class,
        \Illuminate\Database\Seeder::class,
        \Illuminate\Foundation\Http\FormRequest::class,
        \Illuminate\Http\Resources\Json\JsonResource::class,
        \Illuminate\Http\Resources\Json\ResourceCollection::class,
        \Illuminate\Support\ServiceProvider::class,
        'League\Fractal\TransformerAbstract',
    ];

    public function id(): string
    {
        return 'SL301';
    }

    public function name(): string
    {
        return 'Abstraction Inflation';
    }

    public function description(): string
    {
        return 'Flags concepts wrapped in several layers where at least one layer is trivial, singly implemented or singly used.';
    }

    public function explanation(): string
    {
        return 'Layers pay for themselves when they hide a real choice: a second implementation, a boundary that '
            .'gets faked, a shape the caller should not see. When each layer has one implementation, one caller and '
            .'almost no code, the indirection is all cost -- more files to open to follow one call, and no '
            .'flexibility gained. This is a judgement about the current usage, not a rule against repositories.';
    }

    public function category(): Category
    {
        return Category::Architecture;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $minLayers = max(2, $this->intOption('min_layers', 3));
        $minSignals = max(1, $this->intOption('min_signals', 2));
        $maxStatements = max(1, $this->intOption('trivial_max_statements', 12));
        $maxMethods = max(1, $this->intOption('trivial_max_methods', 3));
        $stacks = $this->stacks($context->index);

        foreach ($context->classLikes() as $classLike) {
            $fqn = NodeHelper::className($classLike);
            $shortName = NodeHelper::shortName($classLike);

            if ($fqn === null || $shortName === null) {
                continue;
            }

            foreach ($stacks as $stack) {
                $member = $stack->member($fqn);

                if ($member === null || $stack->depth() < $minLayers) {
                    continue;
                }

                // The concept itself is not an extra layer around anything.
                if ($stack->layerOf($member) === '') {
                    continue;
                }

                // More than one implementation is decisive evidence that an
                // abstraction is earning its place: it is hiding a real choice.
                if (($member->kind === 'interface' || $member->isAbstract) && count($context->index->implementationsOf($member->fqn)) > 1) {
                    continue;
                }

                $signals = $this->signalsFor($context, $member, $maxStatements, $maxMethods);

                // One signal is not enough. An implementation nothing
                // references by name is normal when it is resolved from the
                // container, and a small class is only suspicious when it is
                // also unused or singly implemented.
                if (count($signals) < $minSignals) {
                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: $classLike,
                    message: sprintf(
                        '%s is one of %d layers around "%s" (%s), and %s.',
                        $shortName,
                        $stack->depth(),
                        $stack->noun,
                        implode(', ', $stack->memberNames()),
                        implode('; ', $signals),
                    ),
                    suggestion: sprintf(
                        'This abstraction may be unnecessary for the current usage. If nothing else will implement '
                        .'it and nothing else calls it, collapsing %s into its caller removes a file without '
                        .'removing a capability. Keep it if a second implementation or a test seam is genuinely '
                        .'coming.',
                        $shortName,
                    ),
                    confidence: $this->confidenceFrom(52, [
                        count($signals) >= 2,
                        count($signals) >= 3,
                        $stack->depth() > $minLayers,
                    ], 7, 78),
                    fingerprint: $shortName,
                    metrics: [
                        'noun' => $stack->noun,
                        'layers' => $stack->depth(),
                        'stack' => implode(', ', $stack->memberNames()),
                        'signals' => implode('; ', $signals),
                    ],
                );
            }
        }
    }

    /**
     * The project's layer stacks, without the members that are framework
     * conventions.
     *
     * @return array<string, LayerStack>
     */
    private function stacks(ProjectIndex $index): array
    {
        $bases = $this->listOption('convention_bases', self::CONVENTION_BASES);
        $isConvention = fn (ClassSummary $member): bool => $this->extendsAny($index, $member, $bases);

        return array_map(
            static fn (LayerStack $stack): LayerStack => $stack->without($isConvention),
            LayerStack::group($index, $this->listOption('layer_suffixes', LayerStack::LAYER_SUFFIXES)),
        );
    }

    /**
     * Whether a class extends one of the given bases, following parents
     * through the index for as long as the project declares them.
     *
     * @param  list<string>  $bases
     */
    private function extendsAny(ProjectIndex $index, ClassSummary $member, array $bases): bool
    {
        $seen = [];
        $parent = $member->parent;

        while ($parent !== null && ! isset($seen[$parent])) {
            if (in_array($parent, $bases, true)) {
                return true;
            }

            $seen[$parent] = true;
            $parent = $index->class($parent)?->parent;
        }

        return false;
    }

    /**
     * Evidence that this particular layer is not carrying weight.
     *
     * @return list<string>
     */
    private function signalsFor(AnalysisContext $context, ClassSummary $member, int $maxStatements, int $maxMethods): array
    {
        $signals = [];
        $isAbstraction = $member->kind === 'interface' || $member->isAbstract;

        // Interfaces are supposed to be small, so their size says nothing.
        if (! $isAbstraction && $member->isTrivial($maxStatements, $maxMethods)) {
            $signals[] = sprintf('it holds only %d statements across %d methods', $member->statementCount, $member->methodCount);
        }

        if ($isAbstraction) {
            $implementations = $context->index->implementationsOf($member->fqn);

            if (count($implementations) === 1) {
                $signals[] = sprintf('it has a single implementation (%s)', NodeHelper::baseName($implementations[0]));
            }
        }

        $usages = $context->index->usageCount($member->fqn);

        if ($usages <= 1) {
            $signals[] = $usages === 0
                ? 'nothing in the analysed paths references it'
                : 'exactly one file references it';
        }

        return $signals;
    }
}
