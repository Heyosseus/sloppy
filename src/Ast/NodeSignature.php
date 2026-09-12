<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

/**
 * A node's shape and the detail that describing its shape discarded.
 *
 * Structural hashing deliberately masks local variable names, literal values
 * and the class a static call targets, because two methods doing the same
 * thing to different variables are duplicates. Judging whether that is a
 * duplicate or a mistake needs the masked detail back, in the same order the
 * tokens were produced, which is why both come out of one traversal together.
 */
final readonly class NodeSignature
{
    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $maskedValues
     */
    public function __construct(
        public array $tokens,
        public array $maskedValues,
    ) {}
}
