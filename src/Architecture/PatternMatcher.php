<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * One part of a declaration compared against one or more patterns; any
 * pattern matching is enough.
 *
 * In a glob, `*` stands for any run of characters, backslashes included, so
 * `*Http\Controllers*` means "the name contains `Http\Controllers`". Nothing
 * else is special: a backslash is a namespace separator, never an escape.
 */
final readonly class PatternMatcher implements Matcher
{
    /**
     * @var list<string>
     */
    private array $expressions;

    /**
     * @param  list<string>  $patterns
     */
    public function __construct(
        public MatchKey $key,
        public array $patterns,
    ) {
        $this->expressions = $key->isGlob()
            ? array_map($this->compile(...), $patterns)
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

        foreach ($this->expressions as $expression) {
            if (preg_match($expression, $subject) === 1) {
                return true;
            }
        }

        return false;
    }

    private function compile(string $glob): string
    {
        $parts = array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            explode('*', $glob),
        );

        return '/^'.implode('.*', $parts).'$/s';
    }
}
