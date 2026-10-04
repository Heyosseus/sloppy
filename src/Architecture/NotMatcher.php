<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * The inner matcher must fail: a role's `not` key.
 */
final readonly class NotMatcher implements Matcher
{
    public function __construct(public Matcher $matcher) {}

    public function matches(ClassFacts $facts): bool
    {
        return ! $this->matcher->matches($facts);
    }

    public function describe(): string
    {
        return 'not ('.$this->matcher->describe().')';
    }
}
