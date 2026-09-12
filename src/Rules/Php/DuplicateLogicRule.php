<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\DuplicateBlock;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Rules\BaseRule;

/**
 * SL104 -- method bodies that are structurally the same code twice.
 *
 * Detection is deliberately deterministic: two bodies match when their AST
 * shapes, control flow and call names are identical after local variable names
 * and literal values are normalised away. No similarity scoring, no embeddings.
 */
final class DuplicateLogicRule extends BaseRule
{
    public function id(): string
    {
        return 'SL104';
    }

    public function name(): string
    {
        return 'Duplicate Logic';
    }

    public function description(): string
    {
        return 'Flags methods whose bodies are structurally identical to another method in the project.';
    }

    public function explanation(): string
    {
        return 'Two methods with the same structure and the same calls tend to drift apart: a fix applied to one '
            .'copy silently leaves the other broken. Because variable names and literals are normalised away, a '
            .'match means the code has the same shape -- confirm the behaviour really is the same before merging '
            .'them.';
    }

    public function category(): Category
    {
        return Category::Duplication;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $minStatements = max(2, $this->intOption('min_statements', 8));
        $ignoredMethods = $this->listOption('ignore_methods', ['__construct', '__invoke', 'up', 'down', 'definition']);

        foreach ($context->classLikes() as $classLike) {
            $className = NodeHelper::shortName($classLike);

            if ($className === null) {
                continue;
            }

            foreach (NodeHelper::methods($classLike) as $method) {
                $methodName = $method->name->toString();

                if ($method->stmts === null || $method->stmts === []) {
                    continue;
                }

                if (NodeHelper::isNameOneOf($methodName, $ignoredMethods)) {
                    continue;
                }

                if (NodeHelper::countStatements($method) < $minStatements) {
                    continue;
                }

                $hash = ProjectIndex::blockHash($method);
                $matches = $context->index->blocksMatching($hash);

                $others = array_values(array_filter(
                    $matches,
                    static fn (DuplicateBlock $block): bool => $block->relativePath !== $context->relativePath()
                        || $block->className !== $className
                        || $block->methodName !== $methodName,
                ));

                if ($others === []) {
                    continue;
                }

                $crossFile = false;

                foreach ($others as $other) {
                    if ($other->relativePath !== $context->relativePath()) {
                        $crossFile = true;

                        break;
                    }
                }

                $statements = NodeHelper::countStatements($method);

                yield $this->report(
                    context: $context,
                    at: $method,
                    message: sprintf(
                        '%s::%s() is structurally identical to %s.',
                        $className,
                        $methodName,
                        $this->describe($others),
                    ),
                    suggestion: 'If the two really do the same work, keep one and call it from both places. If they '
                        .'differ only in the values they use, check those values against each other first -- SL111 '
                        .'reports the ones that look like an unfinished copy -- and parameterise only once you are '
                        .'satisfied every difference is deliberate.',
                    confidence: $this->confidenceFrom(70, [
                        $statements >= $minStatements * 2,
                        $crossFile,
                        count($others) > 1,
                    ], 7, 90),
                    fingerprint: $className.'::'.$methodName,
                    metrics: [
                        'statements' => $statements,
                        'occurrences' => count($others) + 1,
                        'structural_hash' => substr($hash, 0, 12),
                    ],
                );
            }
        }
    }

    /**
     * @param  list<DuplicateBlock>  $blocks
     */
    private function describe(array $blocks): string
    {
        $labels = array_map(
            static fn (DuplicateBlock $block): string => $block->label().' at '.$block->reference(),
            array_slice($blocks, 0, 3),
        );

        $described = implode(', ', $labels);

        return count($blocks) > 3
            ? $described.sprintf(' and %d more', count($blocks) - 3)
            : $described;
    }
}
