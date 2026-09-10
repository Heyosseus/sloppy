<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Laravel;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * SL209 -- models that reach beyond persistence.
 *
 * Relations, scopes, casts and accessors are what models are for and are never
 * counted here. What is counted is a model sending mail, queueing jobs, calling
 * a third party, or hosting a multi-step workflow -- work that runs whenever
 * the model is touched and cannot be tested without it.
 */
final class ModelDoingTooMuchRule extends BaseRule
{
    public function id(): string
    {
        return 'SL209';
    }

    public function name(): string
    {
        return 'Model Doing Too Much';
    }

    public function description(): string
    {
        return 'Flags Eloquent models that make outbound calls, dispatch notifications or jobs, or contain long business workflows.';
    }

    public function explanation(): string
    {
        return 'Behaviour on a model runs wherever the model is loaded, including in factories, seeders and tests, '
            .'and it is reachable from anywhere the model is. Persistence, relations and presentation of a row '
            .'belong on the model; deciding what the business should do next generally does not.';
    }

    public function category(): Category
    {
        return Category::Laravel;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $maxWorkflowLines = max(10, $this->intOption('max_method_lines', 40));
        $minSignals = max(1, $this->intOption('min_signals', 1));

        foreach ($context->classLikes() as $classLike) {
            if (! NodeHelper::isEloquentModel($classLike)) {
                continue;
            }

            $name = NodeHelper::shortName($classLike);

            if ($name === null) {
                continue;
            }

            $http = $this->countHttp($classLike);
            $dispatches = $this->countDispatches($classLike);
            $workflows = $this->longBusinessMethods($classLike, $maxWorkflowLines);

            $evidence = [];

            if ($http > 0) {
                $evidence[] = sprintf('%d outbound HTTP call(s)', $http);
            }

            if ($dispatches > 0) {
                $evidence[] = sprintf('%d notification or queue dispatch(es)', $dispatches);
            }

            if ($workflows !== []) {
                $evidence[] = sprintf(
                    '%d method(s) longer than %d lines (%s)',
                    count($workflows),
                    $maxWorkflowLines,
                    implode(', ', array_map(static fn (string $method): string => $method.'()', $workflows)),
                );
            }

            if (count($evidence) < $minSignals) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $classLike,
                message: sprintf('Model %s does more than persist a row: %s.', $name, implode('; ', $evidence)),
                suggestion: 'Move the outbound work into a listener on the relevant model event, a job, or a '
                    .'service the caller invokes explicitly. Keeping relations, casts, scopes and accessors here is '
                    .'exactly right -- it is the deciding and the sending that want to live elsewhere.',
                confidence: $this->confidenceFrom(66, [
                    $http > 0,
                    $dispatches > 0,
                    count($workflows) > 1,
                ], 8, 88),
                fingerprint: $name,
                metrics: [
                    'http_calls' => $http,
                    'dispatches' => $dispatches,
                    'long_methods' => count($workflows),
                ],
            );
        }
    }

    private function countHttp(ClassLike $classLike): int
    {
        $count = 0;

        foreach ([StaticCall::class, New_::class, FuncCall::class] as $type) {
            foreach (NodeHelper::find($classLike, $type) as $node) {
                if (LaravelCalls::isHttpCall($node)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function countDispatches(ClassLike $classLike): int
    {
        $count = 0;

        foreach ([StaticCall::class, MethodCall::class, FuncCall::class] as $type) {
            foreach (NodeHelper::find($classLike, $type) as $node) {
                if (! LaravelCalls::isDispatch($node)) {
                    continue;
                }

                // Wiring model events in boot()/booted() is where Laravel
                // itself puts them, so it is not evidence of overreach.
                $method = NodeHelper::enclosingMethod($node)?->name->toString();

                if (NodeHelper::isNameOneOf($method, ['boot', 'booted'])) {
                    continue;
                }

                $count++;
            }
        }

        return $count;
    }

    /**
     * Long methods that are not part of a model's ordinary vocabulary.
     *
     * @return list<string>
     */
    private function longBusinessMethods(ClassLike $classLike, int $maxLines): array
    {
        $found = [];

        foreach (NodeHelper::methods($classLike) as $method) {
            if ($this->isModelVocabulary($method)) {
                continue;
            }

            if (NodeHelper::lineSpan($method) > $maxLines) {
                $found[] = $method->name->toString();
            }
        }

        return $found;
    }

    /**
     * Whether a method is one of the things a model is supposed to have:
     * a relation, a scope, an accessor, a mutator, a cast definition or a
     * framework hook.
     */
    private function isModelVocabulary(ClassMethod $method): bool
    {
        $name = $method->name->toString();

        if (str_starts_with($name, 'scope') || str_starts_with($name, '__')) {
            return true;
        }

        if (preg_match('/^(get|set)[A-Z].*Attribute$/', $name) === 1) {
            return true;
        }

        if (NodeHelper::isNameOneOf($name, ['casts', 'boot', 'booted', 'newFactory', 'getRouteKeyName', 'getRouteKey', 'toArray', 'toSearchableArray'])) {
            return true;
        }

        // A relation is a short method returning a relation builder.
        $returnType = NodeHelper::typeToString($method->returnType);

        return $returnType !== null && preg_match('/(HasOne|HasMany|HasOneThrough|HasManyThrough|BelongsTo|BelongsToMany|MorphTo|MorphOne|MorphMany|MorphToMany|Attribute|CastsAttributes)/', $returnType) === 1;
    }
}
