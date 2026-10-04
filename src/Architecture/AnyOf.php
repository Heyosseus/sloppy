<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * At least one matcher must pass: a role's `any` key.
 */
final readonly class AnyOf implements Matcher
{
    /**
     * @param  non-empty-list<Matcher>  $matchers
     */
    public function __construct(public array $matchers) {}

    public function matches(ClassFacts $facts): bool
    {
        foreach ($this->matchers as $matcher) {
            if ($matcher->matches($facts)) {
                return true;
            }
        }

        return false;
    }

    public function describe(): string
    {
        return 'any of ('.implode('; ', array_map(
            static fn (Matcher $matcher): string => $matcher->describe(),
            $this->matchers,
        )).')';
    }
}
