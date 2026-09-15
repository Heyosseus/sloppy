<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * SL501 -- an analyser was silenced and nobody said why.
 *
 * The rule is deliberately not about how many suppressions a file has. A file
 * carrying ten that each name a bad vendor stub is a file whose author did the
 * work; a file carrying one bare `@psalm-suppress` is a file where an error was
 * made to go away. Density scores those backwards.
 *
 * An unexplained suppression is a claim that the analyser is wrong, made by
 * someone who did not have to defend it, and it can never be removed later
 * because no reader can tell what it was protecting.
 *
 * The vocabulary is configuration rather than code. Every analyser spells this
 * differently and more of them keep appearing, so a team adopting one should
 * edit `sloppy.rules.SL501.annotations` rather than wait for a release.
 */
final class UnexplainedSuppressionRule extends BaseRule
{
    /**
     * @var list<string>
     */
    private const array DEFAULT_ANNOTATIONS = [
        '@phpstan-ignore',
        '@psalm-suppress',
        '@mago-expect',
        '@noinspection',
        'phpcs:ignore',
        '@SuppressWarnings',
    ];

    public function id(): string
    {
        return 'SL501';
    }

    public function name(): string
    {
        return 'Unexplained Suppression';
    }

    public function description(): string
    {
        return 'Flags static-analysis suppressions that give no reason for silencing the analyser.';
    }

    public function explanation(): string
    {
        return 'A suppression is a claim that the analyser is wrong about this line. Written with a reason it is a '
            .'reviewed decision someone can check and eventually delete; written bare it is permanent, because no '
            .'later reader can tell what it was protecting or whether the problem is still there.';
    }

    public function category(): Category
    {
        return Category::Suppression;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Medium;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $annotations = $this->listOption('annotations', self::DEFAULT_ANNOTATIONS);

        foreach ($this->comments($context) as $comment) {
            $text = $comment->getText();

            foreach ($annotations as $annotation) {
                if (! $this->isBare($text, $annotation)) {
                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: $context->locateLine($comment->getStartLine()),
                    message: sprintf('%s with no reason given.', $annotation),
                    suggestion: sprintf(
                        'Say why in the same comment, e.g. `%s the vendor stub declares the wrong return type`.',
                        $annotation,
                    ),
                    confidence: 90,
                    fingerprint: $annotation.'@'.$comment->getStartLine(),
                    metrics: ['annotation' => $annotation],
                );
            }
        }
    }

    /**
     * Every comment in the file, once each, in source order.
     *
     * Comments hang off the node they precede, and a docblock before a method
     * inside a class is reachable from more than one node while walking, so
     * they are keyed by file position to deduplicate.
     *
     * @return list<Comment>
     */
    private function comments(AnalysisContext $context): array
    {
        $comments = [];

        $nodes = (new NodeFinder)->find(
            $context->ast(),
            static fn (Node $node): bool => $node->getComments() !== [],
        );

        foreach ($nodes as $node) {
            foreach ($node->getComments() as $comment) {
                $comments[$comment->getStartFilePos()] = $comment;
            }
        }

        ksort($comments);

        return array_values($comments);
    }

    /**
     * True when the comment applies this annotation and gives no reason.
     *
     * A suppression is a directive, so it begins its line: everything before
     * it is comment furniture. Prose that merely mentions one has words in
     * front of it, and this rule's own source -- which explains what
     * `@psalm-suppress` is -- was the first thing to prove that distinction
     * necessary.
     */
    private function isBare(string $text, string $annotation): bool
    {
        $lines = preg_split('/\R/', $text) ?: [];

        foreach ($lines as $index => $line) {
            $directive = (string) preg_replace('#^[\s*/]+#', '', $line);

            if (mb_stripos($directive, $annotation) !== 0) {
                continue;
            }

            // A reason may wrap onto the rest of the comment, because real
            // docblocks wrap. Requiring it on the annotation's own line would
            // train people to write a shorter reason, not a better one.
            $rest = mb_substr($directive, mb_strlen($annotation))
                .' '.implode(' ', array_slice($lines, $index + 1));

            if (! $this->hasReason($rest)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether anything after the annotation actually says why.
     */
    private function hasReason(string $rest): bool
    {
        // The annotation's own suffix is not a reason: `-next-line` and `-line`
        // say where to look, not why.
        $rest = (string) preg_replace('/^-[a-z-]+/i', '', $rest);

        // Nor is the identifier the analyser itself requires: it names the
        // error being silenced, which the reader can already see. It is the
        // sentence after it that is missing.
        $rest = (string) preg_replace('#^[\s*/]*[A-Za-z][A-Za-z0-9_.]*#', '', $rest);

        return preg_match('/[A-Za-z0-9]/', (string) preg_replace('#[\s*/]+#', '', $rest)) === 1;
    }
}
