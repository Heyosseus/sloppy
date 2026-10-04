<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * One part of a declaration compared against one or more patterns; any
 * pattern matching is enough. Patterns are {@see Glob}s, except for a suffix
 * and a kind, which are compared as written.
 */
final readonly class PatternMatcher implements Matcher
{
    /**
     * @var list<Glob>
     */
    private array $globs;

    /**
     * @param  list<string>  $patterns
     */
    public function __construct(
        public MatchKey $key,
        public array $patterns,
    ) {
        $this->globs = $key->isGlob()
            ? array_map(static fn (string $pattern): Glob => new Glob($pattern), $patterns)
            : [];
    }

    public function matches(ClassFacts $facts): bool
    {
        foreach ($this->key->subjects($facts) as $subject) {
            if ($this->matchesSubject($subject)) {
                return true;
            }
        }

        return false;
    }

    public function describe(): string
    {
        return sprintf('%s %s', $this->key->value, implode(' or ', $this->patterns));
    }

    private function matchesSubject(string $subject): bool
    {
        if ($this->key === MatchKey::Suffix) {
            foreach ($this->patterns as $suffix) {
                if (str_ends_with($subject, $suffix)) {
                    return true;
                }
            }

            return false;
        }

        if ($this->key === MatchKey::Kind) {
            return in_array($subject, $this->patterns, true);
        }

        foreach ($this->globs as $glob) {
            if ($glob->matches($subject)) {
                return true;
            }
        }

        return false;
    }
}
