<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Contracts\Rule;
use Heyosseus\Sloppy\Rules\Architecture\AbstractionInflationRule;
use Heyosseus\Sloppy\Rules\Architecture\EmptyWrapperClassRule;
use Heyosseus\Sloppy\Rules\Architecture\SingleUseAbstractionRule;
use Heyosseus\Sloppy\Rules\Laravel\BusinessLogicInControllerRule;
use Heyosseus\Sloppy\Rules\Laravel\CollectionInsteadOfQueryRule;
use Heyosseus\Sloppy\Rules\Laravel\DirectExternalApiRule;
use Heyosseus\Sloppy\Rules\Laravel\ExcessiveControllerDependenciesRule;
use Heyosseus\Sloppy\Rules\Laravel\ExcessiveServiceDependenciesRule;
use Heyosseus\Sloppy\Rules\Laravel\InlineValidationRule;
use Heyosseus\Sloppy\Rules\Laravel\ModelDoingTooMuchRule;
use Heyosseus\Sloppy\Rules\Laravel\PossibleNPlusOneRule;
use Heyosseus\Sloppy\Rules\Laravel\QueryInsideLoopRule;
use Heyosseus\Sloppy\Rules\Laravel\SuspiciousModelAllRule;
use Heyosseus\Sloppy\Rules\Php\DeadPrivateMethodRule;
use Heyosseus\Sloppy\Rules\Php\DefensiveProgrammingNoiseRule;
use Heyosseus\Sloppy\Rules\Php\DuplicateLogicRule;
use Heyosseus\Sloppy\Rules\Php\ExcessiveNestingRule;
use Heyosseus\Sloppy\Rules\Php\GodClassRule;
use Heyosseus\Sloppy\Rules\Php\GodMethodRule;
use Heyosseus\Sloppy\Rules\Php\NarrativeCommentRule;
use Heyosseus\Sloppy\Rules\Php\RedundantConditionRule;
use Heyosseus\Sloppy\Rules\Php\SwallowedExceptionRule;
use Heyosseus\Sloppy\Rules\Php\UnusedConstructorDependencyRule;

/**
 * The set of rules a run will use.
 *
 * Rules are instantiated once per run and reused across files. Registration
 * order is the order rules appear here, and findings are sorted afterwards, so
 * output does not depend on it.
 */
final readonly class RuleRegistry
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(private array $rules = []) {}

    /**
     * Every rule shipped with the package.
     *
     * @return list<class-string<Rule>>
     */
    public static function shipped(): array
    {
        return [
            GodMethodRule::class,
            GodClassRule::class,
            ExcessiveNestingRule::class,
            DuplicateLogicRule::class,
            DeadPrivateMethodRule::class,
            UnusedConstructorDependencyRule::class,
            SwallowedExceptionRule::class,
            RedundantConditionRule::class,
            NarrativeCommentRule::class,
            DefensiveProgrammingNoiseRule::class,

            BusinessLogicInControllerRule::class,
            InlineValidationRule::class,
            PossibleNPlusOneRule::class,
            QueryInsideLoopRule::class,
            CollectionInsteadOfQueryRule::class,
            ExcessiveControllerDependenciesRule::class,
            ExcessiveServiceDependenciesRule::class,
            DirectExternalApiRule::class,
            ModelDoingTooMuchRule::class,
            SuspiciousModelAllRule::class,

            AbstractionInflationRule::class,
            EmptyWrapperClassRule::class,
            SingleUseAbstractionRule::class,
        ];
    }

    /**
     * Build the registry the configuration asks for: shipped rules plus any
     * the application registered, minus the ones it disabled, each configured
     * with its own options.
     */
    public static function fromConfiguration(Configuration $config): self
    {
        $rules = [];

        foreach ([...self::shipped(), ...$config->customRules()] as $class) {
            $rule = new $class;
            $id = $rule->id();

            if (! $config->isRuleEnabled($id)) {
                continue;
            }

            $options = $config->ruleOptions($id);
            $rules[] = $options === [] ? $rule : new $class($options);
        }

        return new self($rules);
    }

    /**
     * Every shipped rule with default options, for documentation and for tests
     * that do not care about configuration.
     */
    public static function withDefaults(): self
    {
        return new self(array_map(
            static fn (string $class): Rule => new $class,
            self::shipped(),
        ));
    }

    /**
     * @param  list<Rule>  $rules
     */
    public static function of(array $rules): self
    {
        return new self($rules);
    }

    /**
     * Narrow the registry to specific rule IDs.
     *
     * @param  list<string>  $ids
     */
    public function only(array $ids): self
    {
        $wanted = array_map(mb_strtoupper(...), $ids);

        return new self(array_values(array_filter(
            $this->rules,
            static fn (Rule $rule): bool => in_array(mb_strtoupper($rule->id()), $wanted, true),
        )));
    }

    /**
     * @return list<Rule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function count(): int
    {
        return count($this->rules);
    }

    public function get(string $id): ?Rule
    {
        foreach ($this->rules as $rule) {
            if ($rule->id() === $id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (Rule $rule): string => $rule->id(), $this->rules);
    }
}
