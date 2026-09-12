<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Ast;

/**
 * One method body in the form two bodies can be compared in.
 *
 * The token stream is kept joined rather than as an array. Measured on a
 * 1,075-file application: 552 bodies, 862 KB of token text, and holding them
 * as PHP string arrays cost 18 MB of peak memory for a comparison that only 14
 * pairs out of 152,076 ever reach. Splitting on demand is the cheaper trade.
 */
final readonly class BlockSignature
{
    /**
     * A separator that cannot occur inside a token. Tokens are node class base
     * names and identifiers, so any control character would do; this one is
     * conventional for field separation.
     */
    public const string SEPARATOR = "\x1f";

    /**
     * @param  array<string, int>  $frequencies  Token => how many times it occurs.
     * @param  list<string>  $maskedValues
     */
    public function __construct(
        public DuplicateBlock $block,
        public string $hash,
        public string $tokens,
        public int $tokenCount,
        public array $frequencies,
        public array $maskedValues,
    ) {}

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $maskedValues
     */
    public static function create(DuplicateBlock $block, string $hash, array $tokens, array $maskedValues): self
    {
        $frequencies = [];

        foreach ($tokens as $token) {
            $frequencies[$token] = ($frequencies[$token] ?? 0) + 1;
        }

        return new self(
            block: $block,
            hash: $hash,
            tokens: implode(self::SEPARATOR, $tokens),
            tokenCount: count($tokens),
            frequencies: $frequencies,
            maskedValues: $maskedValues,
        );
    }

    /**
     * @return list<string>
     */
    public function tokenList(): array
    {
        return $this->tokenCount === 0 ? [] : explode(self::SEPARATOR, $this->tokens);
    }

    /**
     * This body's identity, as one string.
     *
     * It lives here because four places were building it by hand from
     * `reference()` and `methodName`, which is the duplication SL104 reports
     * and SL111 now reports the drifted version of.
     */
    public function identity(): string
    {
        return $this->block->reference().'::'.$this->block->methodName;
    }

    /**
     * Two bodies in the same file and class with the same name are the same
     * body, which is the one pair never worth comparing.
     */
    public function isSameBodyAs(self $other): bool
    {
        return $this->block->relativePath === $other->block->relativePath
            && $this->block->className === $other->block->className
            && $this->block->methodName === $other->block->methodName;
    }
}
