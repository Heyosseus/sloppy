<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;

/**
 * SL202 -- substantial validation rule sets written inline in a controller.
 *
 * A two-field check inline is fine and a FormRequest for it would be
 * ceremony. This fires once the rule set is big enough that moving it buys
 * something: a name, reuse, authorisation in the same place, and a controller
 * that fits on a screen.
 */
final class InlineValidationRule extends BaseRule
{
    public function id(): string
    {
        return 'SL202';
    }

    public function name(): string
    {
        return 'Inline Validation';
    }

    public function description(): string
    {
        return 'Flags large inline validation rule sets inside controllers, where a FormRequest would carry them better.';
    }

    public function explanation(): string
    {
        return 'A large inline rule set puts input contract, authorisation and business flow in one method, and '
            .'nothing else can reuse the rules. A FormRequest gives the contract a name and a test, and leaves the '
            .'controller to the flow. Small inline checks are not flagged.';
    }

    public function category(): Category
    {
        return Category::Laravel;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Low;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $maxRules = max(1, $this->intOption('max_rules', 6));
        $controllersOnly = $this->boolOption('controllers_only', true);

        foreach ($context->classLikes() as $classLike) {
            $isController = NodeHelper::isController($classLike);

            if ($controllersOnly && ! $isController) {
                continue;
            }

            // A FormRequest is already the extracted form of this pattern.
            if (NodeHelper::isFormRequest($classLike)) {
                continue;
            }

            $className = NodeHelper::shortName($classLike) ?? 'class';

            foreach ($this->validationArrays($classLike) as $found) {
                $rules = count($found['array']->items);

                if ($rules < $maxRules) {
                    continue;
                }

                $method = NodeHelper::enclosingMethod($found['array']);
                $methodName = $method?->name->toString() ?? 'closure';
                $complex = $this->countComplexRules($found['array']);

                yield $this->report(
                    context: $context,
                    at: $found['array'],
                    message: sprintf(
                        '%s::%s() validates %d fields inline via %s(), %d of them with multiple constraints.',
                        $className,
                        $methodName,
                        $rules,
                        $found['call'],
                        $complex,
                    ),
                    suggestion: sprintf(
                        'Move the rules into a FormRequest (for example %s%sRequest) and type-hint it on the action. '
                        .'The controller then receives already-valid input and the rules become independently testable.',
                        ucfirst($methodName),
                        str_replace('Controller', '', $className),
                    ),
                    confidence: min(92, 74 + intdiv($rules - $maxRules, 2) * 4 + ($complex >= 3 ? 6 : 0)),
                    fingerprint: $className.'::'.$methodName,
                    metrics: [
                        'rules' => $rules,
                        'multi_constraint_rules' => $complex,
                        'via' => $found['call'],
                    ],
                );
            }
        }
    }

    /**
     * Validation rule arrays and the call they were passed to.
     *
     * @return list<array{array: Array_, call: string}>
     */
    private function validationArrays(\PhpParser\Node $subject): array
    {
        $found = [];

        foreach (NodeHelper::find($subject, MethodCall::class) as $call) {
            $name = NodeHelper::callName($call);

            if (! NodeHelper::isNameOneOf($name, ['validate', 'validateWithBag'])) {
                continue;
            }

            $array = $this->firstArrayArgument(array_values($call->getArgs()));

            if ($array instanceof Array_) {
                $found[] = ['array' => $array, 'call' => (string) $name];
            }
        }

        foreach (NodeHelper::find($subject, StaticCall::class) as $call) {
            $class = NodeHelper::staticCallClass($call);

            if ($class === null || NodeHelper::baseName($class) !== 'Validator') {
                continue;
            }

            if (! NodeHelper::isNameOneOf(NodeHelper::callName($call), ['make', 'validate'])) {
                continue;
            }

            $array = $this->firstArrayArgument(array_values(array_slice($call->getArgs(), 1)));

            if ($array instanceof Array_) {
                $found[] = ['array' => $array, 'call' => 'Validator::make'];
            }
        }

        return $found;
    }

    /**
     * @param  list<Arg>  $args
     */
    private function firstArrayArgument(array $args): ?Array_
    {
        foreach ($args as $arg) {
            if ($arg->value instanceof Array_) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * Fields whose rules are a pipe-delimited string or a nested array, which
     * is where inline rule sets get genuinely hard to read.
     */
    private function countComplexRules(Array_ $array): int
    {
        $count = 0;

        foreach ($array->items as $item) {
            $value = $item->value;

            if ($value instanceof Array_ && count($value->items) > 1) {
                $count++;

                continue;
            }

            if ($value instanceof String_ && str_contains($value->value, '|')) {
                $count++;
            }
        }

        return $count;
    }
}
