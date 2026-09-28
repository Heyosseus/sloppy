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
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

/**
 * SL112 -- a function that is only standing in for one.
 *
 * Agents finish a task by making it compile, and the cheapest way to make a
 * method compile is to leave it unwritten: a `// ... existing code ...` where
 * an edit collapsed the body, a `throw new Exception('Not implemented')`, or a
 * TODO above `return [];`. Each one ships a function whose name promises work
 * it does not do.
 *
 * A bare `return [];` is never enough on its own -- default hook methods and
 * null objects return exactly that on purpose. Every finding needs a marker
 * that the author knew the work was missing.
 */
final class PlaceholderImplementationRule extends BaseRule
{
    /**
     * Comments that stand where code was left out. Agents write these when an
     * edit elides the parts of a body it meant to keep, and they are almost
     * never written by hand into a finished function.
     *
     * Each is anchored to the start of the comment, and only short comments
     * are tested: "the rest of the code assumes a tenant is set" is prose
     * about the code, not a hole in it.
     *
     * A bare `// ...` is deliberately absent. Laravel writes it to mean
     * "intentionally empty" -- in empty catch blocks, hook stubs and
     * constructors -- and a scan of the framework found eight of them, every
     * one on purpose.
     *
     * @var list<string>
     */
    private const array ELIDED = [
        '/^\.{2,}\s*\S.*\.{2,}$/u',
        '/^(\.{2,}|…)\s*(existing|rest|remaining|other|previous|same|unchanged)\b/iu',
        '/^(\.{2,}\s*)?(the )?(rest|remainder) of (the |your |this )?(code|implementation|method|logic|function|body)\b/iu',
        '/^(\.{2,}\s*)?(add|insert|put|write|place) (your |the )?(own )?(logic|code|implementation|business logic) here\b/iu',
        '/^(\.{2,}\s*)?(your |the )?(implementation|logic|code) goes here\b/iu',
    ];

    private const int MAX_ELISION_WORDS = 8;

    /**
     * Messages that say the body has not been written *yet*.
     *
     * "Not implemented" on its own, or with "yet", is a placeholder. "Method
     * not implemented by Laravel." is not: it is a decorator declining part
     * of an interface on purpose, and says who decided.
     *
     * @var list<string>
     */
    private const array UNWRITTEN = [
        '/^\s*(this )?(method |function |feature )?(is )?(not (yet )?implemented|unimplemented)( yet)?\s*[.!]*\s*$/iu',
        '/\b(not implemented yet|not yet implemented|to be implemented|implement me|todo|stub)\b/iu',
    ];

    /**
     * Comments that mark work as outstanding.
     */
    private const string TODO = '/\b(todo|fixme|xxx)\b|\bimplement (this|me|later)\b/iu';

    public function id(): string
    {
        return 'SL112';
    }

    public function name(): string
    {
        return 'Placeholder Implementation';
    }

    public function description(): string
    {
        return 'Flags functions whose body is a stand-in: elided-code comments, a "not implemented" throw, or a TODO over a trivial return.';
    }

    public function explanation(): string
    {
        return 'A placeholder compiles, passes type checks and usually passes the tests written alongside it, so '
            .'nothing downstream notices the work is missing until a user does. The caller trusts the name; the '
            .'body answers with an empty array or an exception at runtime. Only bodies that say they are unfinished '
            .'are flagged -- an intentional empty default is not.';
    }

    public function category(): Category
    {
        return Category::DeadCode;
    }

    protected function defaultSeverity(): Severity
    {
        return Severity::High;
    }

    public function analyze(AnalysisContext $context): iterable
    {
        foreach ($context->classLikes() as $classLike) {
            $className = NodeHelper::shortName($classLike) ?? 'anonymous class';

            foreach (NodeHelper::methods($classLike) as $method) {
                $finding = $this->inspect($context, $method, $className.'::'.$method->name->toString());

                if ($finding instanceof Finding) {
                    yield $finding;
                }
            }
        }

        foreach (NodeHelper::find($context->ast(), Function_::class) as $function) {
            $finding = $this->inspect($context, $function, $function->name->toString());

            if ($finding instanceof Finding) {
                yield $finding;
            }
        }
    }

    private function inspect(AnalysisContext $context, ClassMethod|Function_ $function, string $label): ?Finding
    {
        $body = $function->getStmts();

        if ($body === null) {
            return null;
        }

        $comments = $this->comments($body);

        foreach ($comments as $comment) {
            if ($this->isElision($comment)) {
                return $this->report(
                    context: $context,
                    at: $context->locateLine($comment->getStartLine()),
                    message: sprintf('%s() has code elided by a "%s" comment.', $label, $this->textOf($comment)),
                    suggestion: 'Write out the code the comment stands for, or restore it from version control. '
                        .'If the body really is meant to be shorter, delete the comment.',
                    confidence: 92,
                    fingerprint: $label.':elided',
                    metrics: ['kind' => 'elided'],
                );
            }
        }

        $statements = array_values(array_filter($body, static fn (Stmt $statement): bool => ! $statement instanceof Nop));

        $message = $this->unwrittenThrow($statements);

        if ($message !== null) {
            return $this->report(
                context: $context,
                at: $function,
                message: sprintf('%s() only throws "%s".', $label, $message),
                suggestion: 'Implement the method. If it genuinely cannot be supported, say why in the message '
                    .'and throw a specific exception, so callers can tell a design decision from unfinished work.',
                confidence: 90,
                fingerprint: $label.':throws',
                metrics: ['kind' => 'throws'],
            );
        }

        if (! $this->isTrivial($statements)) {
            return null;
        }

        foreach ($comments as $comment) {
            if (preg_match(self::TODO, $comment->getText()) === 1) {
                return $this->report(
                    context: $context,
                    at: $function,
                    message: sprintf('%s() is a TODO over %s.', $label, $statements === [] ? 'an empty body' : 'a trivial return'),
                    suggestion: 'Implement the method before relying on it, or make the missing behaviour loud: '
                        .'throw, or leave the method out until something needs it.',
                    confidence: 85,
                    fingerprint: $label.':todo',
                    metrics: ['kind' => 'todo'],
                    severity: Severity::Medium,
                );
            }
        }

        return null;
    }

    /**
     * The message of a body that does nothing but throw an "unwritten" error.
     *
     * @param  array<Stmt>  $statements
     */
    private function unwrittenThrow(array $statements): ?string
    {
        if (count($statements) !== 1 || ! $statements[0] instanceof Expression) {
            return null;
        }

        $throw = $statements[0]->expr;

        if (! $throw instanceof Throw_ || ! $throw->expr instanceof New_) {
            return null;
        }

        $new = $throw->expr;
        $class = $new->class instanceof Node\Name ? NodeHelper::baseName($new->class->toString()) : '';
        $argument = $new->args[0] ?? null;
        $text = $argument instanceof Node\Arg && $argument->value instanceof String_ ? $argument->value->value : '';

        foreach (self::UNWRITTEN as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $text;
            }
        }

        return preg_match('/NotImplemented/i', $class) === 1 ? $class : null;
    }

    /**
     * Nothing at all, or a lone return of an empty or constant value.
     *
     * @param  array<Stmt>  $statements
     */
    private function isTrivial(array $statements): bool
    {
        if ($statements === []) {
            return true;
        }

        if (count($statements) !== 1 || ! $statements[0] instanceof Return_) {
            return false;
        }

        $value = $statements[0]->expr;

        return match (true) {
            ! $value instanceof Node\Expr => true,
            $value instanceof ConstFetch => in_array($value->name->toLowerString(), ['null', 'true', 'false'], true),
            $value instanceof Array_ => $value->items === [],
            $value instanceof String_ => $value->value === '',
            $value instanceof Int_ => $value->value === 0,
            default => false,
        };
    }

    private function isElision(Comment $comment): bool
    {
        $text = $this->textOf($comment);

        if ($text === '' || count(preg_split('/\s+/u', $text) ?: []) > self::MAX_ELISION_WORDS) {
            return false;
        }

        foreach (self::ELIDED as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The words of a comment without its delimiters, on one line.
     */
    private function textOf(Comment $comment): string
    {
        $lines = preg_split('/\R/', $comment->getText()) ?: [];
        $words = [];

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('#^\s*(/\*+|\*+/?|//+|\#)|\*+/\s*$#', '', $line));

            if ($line !== '') {
                $words[] = $line;
            }
        }

        return implode(' ', $words);
    }

    /**
     * Every comment inside a body, once each, in source order. A comment
     * after the last statement hangs off a `Nop`, so walking all nodes finds
     * it too.
     *
     * @param  array<Stmt>  $body
     * @return list<Comment>
     */
    private function comments(array $body): array
    {
        $comments = [];

        $nodes = (new NodeFinder)->find($body, static fn (Node $node): bool => $node->getComments() !== []);

        foreach ($nodes as $node) {
            foreach ($node->getComments() as $comment) {
                $comments[$comment->getStartFilePos()] = $comment;
            }
        }

        ksort($comments);

        return array_values($comments);
    }
}
