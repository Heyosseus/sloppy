<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\CodeUnit;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Node\FunctionLike;

/**
 * SL113 -- methods and functions whose signature takes more than it should.
 */
final class LongParameterListRule extends BaseRule
{
    public function id(): string
    {
        return 'SL113';
    }

    public function name(): string
    {
        return 'Long Parameter List';
    }

    public function description(): string
    {
        return 'Flags methods and functions that take more parameters than the configured limit.';
    }

    public function explanation(): string
    {
        return 'Every extra parameter is another detail a caller has to get right and in the right order, and '
            .'another fact the reader has to hold in mind to know what the call does. Grouping related values '
            .'into an object turns that list back into one thing to reason about.';
    }

    public function category(): Category
    {
        return Category::Complexity;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $maxParams = max(1, $this->intOption('max_params', 7));
        $ignoredMethods = $this->listOption('ignore_methods', ['__construct']);

        foreach (CodeUnit::inFile($context->file) as $unit) {
            if (! $unit->node instanceof FunctionLike || NodeHelper::isNameOneOf($unit->name, $ignoredMethods)) {
                continue;
            }

            $count = count($unit->node->getParams());

            if ($count <= $maxParams) {
                continue;
            }

            yield $this->report(
                context: $context,
                at: $unit->node,
                message: sprintf(
                    '%s takes %d parameters, past the limit of %d.',
                    $unit->subject(),
                    $count,
                    $maxParams,
                ),
                suggestion: 'Group related parameters into a value object, or split the method so each caller '
                    .'only provides what that part of the work actually needs.',
                confidence: min(95, 70 + ($count - $maxParams) * 8),
                fingerprint: $unit->label(),
                metrics: [
                    'params' => $count,
                    'max_params' => $maxParams,
                ],
            );
        }
    }
}
