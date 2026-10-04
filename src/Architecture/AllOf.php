<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * Every matcher must pass: what the keys of one role definition mean together.
 */
final readonly class AllOf implements Matcher
{
    /**
     * @param  non-empty-list<Matcher>  $matchers
     */
    public function __construct(public array $matchers) {}

    public function matches(ClassFacts $facts): bool
    {
        foreach ($this->matchers as $matcher) {
            if (! $matcher->matches($facts)) {
                return false;
            }
        }

        return true;
    }

    public function describe(): string
    {
        return implode(', and ', array_map(
            static fn (Matcher $matcher): string => $matcher->describe(),
            $this->matchers,
        ));
    }
}
