<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * Turns one role definition from the configuration into a matcher.
 *
 * Keys in one definition must all hold (`kind` and `suffix`), `any` takes a
 * list of definitions of which one must hold, and `not` takes a definition
 * that must not. A key's value is a pattern or a list of patterns, any of
 * which may match.
 */
final readonly class RoleParser
{
    private const string DESCRIPTION = 'description';

    private const string ANY = 'any';

    private const string NOT = 'not';

    /**
     * @param  string  $path  Where the definition sits, for error messages: `sloppy.architecture.roles.controller`.
     */
    public static function parse(string $name, mixed $definition, string $origin, string $path): Role
    {
        if (! is_array($definition)) {
            throw new ProfileException(sprintf(
                '%s must be an array of matchers, or false to remove a preset role.',
                $path,
            ));
        }

        $description = $definition[self::DESCRIPTION] ?? null;

        if ($description !== null && ! is_string($description)) {
            throw new ProfileException(sprintf('%s.%s must be a string.', $path, self::DESCRIPTION));
        }

        return new Role($name, self::matcher($definition, $path), $origin, $description);
    }

    /**
     * @param  array<mixed>  $definition
     */
    private static function matcher(array $definition, string $path): Matcher
    {
        $matchers = [];

        /** @var mixed $value */
        foreach ($definition as $key => $value) {
            if ($key === self::DESCRIPTION) {
                continue;
            }

            $matchers[] = match ($key) {
                self::ANY => self::any($value, $path.'.'.self::ANY),
                self::NOT => new NotMatcher(self::nested($value, $path.'.'.self::NOT)),
                default => self::pattern($key, $value, $path),
            };
        }

        if ($matchers === []) {
            throw new ProfileException(sprintf(
                '%s has no matchers, so it would match nothing. Give it at least one of: %s.',
                $path,
                self::keyList(),
            ));
        }

        return count($matchers) === 1 ? $matchers[0] : new AllOf($matchers);
    }

    private static function any(mixed $value, string $path): AnyOf
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw new ProfileException(sprintf('%s must be a non-empty list of matcher arrays.', $path));
        }

        $matchers = [];

        /** @var mixed $alternative */
        foreach ($value as $position => $alternative) {
            $matchers[] = self::nested($alternative, $path.'.'.$position);
        }

        return new AnyOf($matchers);
    }

    private static function nested(mixed $value, string $path): Matcher
    {
        if (! is_array($value)) {
            throw new ProfileException(sprintf('%s must be an array of matchers.', $path));
        }

        return self::matcher($value, $path);
    }

    private static function pattern(int|string $key, mixed $value, string $path): PatternMatcher
    {
        $matchKey = is_string($key) ? MatchKey::tryFrom($key) : null;

        if (! $matchKey instanceof MatchKey) {
            throw new ProfileException(sprintf(
                '%s has an unknown key [%s]. Use one of: %s.',
                $path,
                (string) $key,
                self::keyList(),
            ));
        }

        $patterns = is_string($value) ? [$value] : $value;

        if (! is_array($patterns) || $patterns === [] || ! array_is_list($patterns)) {
            throw new ProfileException(sprintf('%s.%s must be a string or a non-empty list of strings.', $path, $key));
        }

        $strings = [];

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                throw new ProfileException(sprintf('%s.%s must be a string or a non-empty list of strings.', $path, $key));
            }

            $strings[] = trim($pattern);
        }

        if ($matchKey === MatchKey::Kind) {
            foreach ($strings as $kind) {
                if (! in_array($kind, MatchKey::KINDS, true)) {
                    throw new ProfileException(sprintf(
                        '%s.kind has an unknown kind [%s]. Use one of: %s.',
                        $path,
                        $kind,
                        implode(', ', MatchKey::KINDS),
                    ));
                }
            }
        }

        return new PatternMatcher($matchKey, $strings);
    }

    private static function keyList(): string
    {
        return implode(', ', [
            ...array_map(static fn (MatchKey $key): string => $key->value, MatchKey::cases()),
            self::ANY,
            self::NOT,
        ]);
    }
}
