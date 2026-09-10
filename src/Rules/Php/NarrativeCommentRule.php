<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules\Php;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Rules\BaseRule;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt;

/**
 * SL109 -- comments that restate the line below them.
 *
 * Comment style is a matter of taste, so this rule is deliberately timid: it
 * only fires when every meaningful word in a short comment already appears in
 * the code it sits above, which is the signature of narration rather than
 * explanation. A comment saying *why* survives, because its words are not in
 * the code.
 */
final class NarrativeCommentRule extends BaseRule
{
    /**
     * Words carrying no information about the subject, stripped before the
     * comment is compared to the code.
     *
     * @var list<string>
     */
    private const array FILLER = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'do', 'does', 'each', 'for', 'from',
        'has', 'have', 'if', 'in', 'into', 'is', 'it', 'its', 'no', 'not', 'now', 'of', 'on', 'only',
        'or', 'our', 'over', 'so', 'the', 'their', 'then', 'there', 'this', 'to', 'us', 'was', 'we',
        'when', 'while', 'with', 'yes', 'all', 'any', 'here', 'just', 'also', 'very', 'really',
        'check', 'checks', 'checking', 'get', 'gets', 'getting', 'set', 'sets', 'setting',
        'return', 'returns', 'returning', 'create', 'creates', 'creating', 'make', 'makes',
        'loop', 'loops', 'looping', 'iterate', 'iterates', 'call', 'calls', 'calling',
        'run', 'runs', 'running', 'add', 'adds', 'adding', 'exist', 'exists', 'existing',
        'try', 'trying', 'ensure', 'ensures', 'handle', 'handles', 'handling', 'use', 'uses', 'using',
        'define', 'defines', 'declare', 'declares', 'start', 'starts', 'begin', 'begins',
        'end', 'ends', 'finish', 'finishes', 'initialize', 'initializes', 'instantiate',
        'new', 'value', 'values', 'data', 'result', 'results', 'first', 'build', 'builds',
    ];

    /**
     * Comment prefixes that are instructions to tools or to people, never
     * narration.
     *
     * @var list<string>
     */
    private const array DIRECTIVE_PREFIXES = [
        '@', 'todo', 'fixme', 'hack', 'note', 'xxx', 'phpcs', 'phpstan', 'psalm', 'pint',
        'codingstandards', 'noinspection', 'sloppy-ignore', 'phan',
    ];

    public function id(): string
    {
        return 'SL109';
    }

    public function name(): string
    {
        return 'Narrative Comment';
    }

    public function description(): string
    {
        return 'Flags short comments whose every meaningful word already appears in the statement directly below them.';
    }

    public function explanation(): string
    {
        return 'A comment that repeats the code costs a line to read and gains nothing, and it silently goes stale '
            .'when the code changes. Comments explaining why a decision was made are not flagged, because their '
            .'words are not in the code. This rule is subjective by nature -- turn it off if it does not match how '
            .'your team writes.';
    }

    public function category(): Category
    {
        return Category::Readability;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::Low;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        $maxWords = max(2, $this->intOption('max_words', 8));
        $detectSteps = $this->boolOption('detect_step_comments', true);

        /** @var array<int, true> $seen */
        $seen = [];

        foreach (NodeHelper::find($context->ast(), Stmt::class) as $statement) {
            $comments = $statement->getComments();

            // A block containing a rule of dashes is a section heading, and the
            // words in a heading are meant to echo the code beneath it.
            if ($this->isSectionHeading($comments)) {
                continue;
            }

            foreach ($comments as $comment) {
                if ($comment instanceof Doc) {
                    continue;
                }

                $line = $comment->getStartLine();

                if (isset($seen[$line])) {
                    continue;
                }

                $seen[$line] = true;

                $text = $this->textOf($comment);

                if ($text === null) {
                    continue;
                }

                if ($detectSteps && $this->isStepNarration($text)) {
                    yield $this->stepFinding($context, $statement, $line, $text);

                    continue;
                }

                $significant = $this->significantWords($text);

                if ($significant === [] || count($this->words($text)) > $maxWords) {
                    continue;
                }

                $codeWords = $this->codeWords($context, $statement);

                if ($codeWords === [] || ! $this->allPresent($significant, $codeWords)) {
                    continue;
                }

                yield $this->report(
                    context: $context,
                    at: $context->locateLine($line),
                    message: sprintf('Comment "%s" restates the code below it.', $text),
                    suggestion: 'Delete the comment, or replace it with the reason the code is written this way -- '
                        .'the constraint, the bug it works around, the decision behind it.',
                    confidence: 62,
                    fingerprint: $this->fingerprintFor($statement, $text),
                    metrics: [
                        'comment' => $text,
                        'significant_words' => implode(' ', $significant),
                    ],
                );
            }
        }
    }

    private function stepFinding(AnalysisContext $context, Stmt $statement, int $line, string $text): Finding
    {
        return $this->report(
            context: $context,
            at: $context->locateLine($line),
            message: sprintf('Comment "%s" narrates a step of the surrounding method.', $text),
            suggestion: 'Numbered narration usually marks a section that wants to be its own method. Extract the '
                .'block and let its name replace the comment.',
            confidence: 70,
            fingerprint: $this->fingerprintFor($statement, $text),
            metrics: ['comment' => $text],
        );
    }

    /**
     * Whether a run of comments is a section heading rather than narration.
     *
     * @param  array<Comment>  $comments
     */
    private function isSectionHeading(array $comments): bool
    {
        foreach ($comments as $comment) {
            $text = trim(ltrim(trim($comment->getText()), '/#'));

            if ($text !== '' && preg_match('/^[-=*_\s]{3,}$/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comment body, or null when the comment is not the kind this rule judges.
     */
    private function textOf(Comment $comment): ?string
    {
        $raw = trim($comment->getText());

        if (! str_starts_with($raw, '//') && ! str_starts_with($raw, '#')) {
            return null;
        }

        $text = trim(ltrim($raw, '/#'));

        if ($text === '' || preg_match('/^[-=*_\s]+$/', $text) === 1) {
            return null;
        }

        // Commented-out code is a different problem, and not this rule's.
        if (preg_match('/[$;{}]|\(\s*\)|->|::/', $text) === 1) {
            return null;
        }

        foreach (self::DIRECTIVE_PREFIXES as $prefix) {
            if (str_starts_with(mb_strtolower($text), $prefix)) {
                return null;
            }
        }

        return $text;
    }

    private function isStepNarration(string $text): bool
    {
        return preg_match('/^(step\s*\d+|\d+\s*[.):])/i', $text) === 1;
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        preg_match_all('/[A-Za-z]+/', $text, $matches);

        return array_map(mb_strtolower(...), $matches[0]);
    }

    /**
     * @return list<string>
     */
    private function significantWords(string $text): array
    {
        $words = [];

        foreach ($this->words($text) as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::FILLER, true)) {
                continue;
            }

            $words[] = $word;
        }

        return array_values(array_unique($words));
    }

    /**
     * Identifier words on the line the comment introduces.
     *
     * @return list<string>
     */
    private function codeWords(AnalysisContext $context, Stmt $statement): array
    {
        $line = $context->file->lineAt($statement->getStartLine()) ?? '';

        if (trim($line) === '') {
            $line = NodeHelper::printAny($statement);
        }

        $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $line);
        preg_match_all('/[A-Za-z]+/', $spaced, $matches);

        return array_values(array_unique(array_map(mb_strtolower(...), $matches[0])));
    }

    /**
     * @param  list<string>  $needles
     * @param  list<string>  $haystack
     */
    private function allPresent(array $needles, array $haystack): bool
    {
        foreach ($needles as $needle) {
            if (! $this->present($needle, $haystack)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $haystack
     */
    private function present(string $needle, array $haystack): bool
    {
        foreach ($haystack as $word) {
            if ($word === $needle) {
                return true;
            }

            // Tolerate plurals and simple stems so "prices" matches "price".
            if (mb_strlen($needle) >= 4 && str_starts_with($word, $needle)) {
                return true;
            }

            if (mb_strlen($word) >= 4 && str_starts_with($needle, $word)) {
                return true;
            }
        }

        return false;
    }

    private function fingerprintFor(Stmt $statement, string $text): string
    {
        $scope = NodeHelper::enclosingMethod($statement)?->name->toString() ?? 'file';
        $slug = mb_substr((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($text)), 0, 48);

        return $scope.':'.trim($slug, '-');
    }
}
