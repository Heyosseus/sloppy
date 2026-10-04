<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * Turns `sloppy.architecture.policies.<role>` and
 * `sloppy.architecture.boundaries` into value objects, refusing anything that
 * would quietly enforce nothing.
 */
final readonly class PolicyParser
{
    private const array POLICY_KEYS = ['may_depend_on', 'may_not_depend_on', 'may_not', 'public_methods', 'final', 'advice'];

    private const array BOUNDARY_KEYS = ['modules', 'public', 'shared'];

    /**
     * @param  list<string>  $roles  Every role the profile defines, so a policy cannot name one that does not exist.
     */
    public static function policy(string $role, mixed $definition, string $origin, string $path, array $roles): Policy
    {
        if (! is_array($definition) || $definition === [] || array_is_list($definition)) {
            throw new ProfileException(sprintf(
                '%s must be an array with any of: %s.',
                $path,
                implode(', ', self::POLICY_KEYS),
            ));
        }

        self::rejectUnknownKeys($definition, self::POLICY_KEYS, $path);

        $advice = $definition['advice'] ?? null;

        if ($advice !== null && (! is_string($advice) || trim($advice) === '')) {
            throw new ProfileException(sprintf('%s.advice must be a sentence.', $path));
        }

        $final = $definition['final'] ?? false;

        if (! is_bool($final)) {
            throw new ProfileException(sprintf('%s.final must be true or false.', $path));
        }

        return new Policy(
            role: $role,
            origin: $origin,
            mayDependOn: array_key_exists('may_depend_on', $definition)
                ? self::targets($definition['may_depend_on'], $path.'.may_depend_on', $roles)
                : null,
            mayNotDependOn: array_key_exists('may_not_depend_on', $definition)
                ? self::targets($definition['may_not_depend_on'], $path.'.may_not_depend_on', $roles)
                : [],
            mayNot: array_key_exists('may_not', $definition)
                ? self::capabilities($definition['may_not'], $path.'.may_not')
                : [],
            advice: is_string($advice) ? trim($advice) : null,
            publicMethods: array_key_exists('public_methods', $definition)
                ? self::globs($definition['public_methods'], $path.'.public_methods')
                : null,
            final: $final,
        );
    }

    public static function boundaries(mixed $definition, string $origin, string $path): Boundaries
    {
        if (! is_array($definition) || array_is_list($definition)) {
            throw new ProfileException(sprintf('%s must be an array with modules, and optionally public and shared.', $path));
        }

        self::rejectUnknownKeys($definition, self::BOUNDARY_KEYS, $path);

        $modules = self::strings($definition['modules'] ?? null, $path.'.modules', allowEmpty: false);

        foreach ($modules as $pattern) {
            if (substr_count($pattern, '{module}') !== 1) {
                throw new ProfileException(sprintf(
                    '%s.modules [%s] must contain {module} exactly once, such as "App\Modules\{module}\*".',
                    $path,
                    $pattern,
                ));
            }
        }

        return new Boundaries(
            origin: $origin,
            modules: $modules,
            public: self::globs($definition['public'] ?? [], $path.'.public'),
            shared: self::globs($definition['shared'] ?? [], $path.'.shared'),
        );
    }

    /**
     * A string or a list of strings as globs. Empty is allowed: no public
     * surface, no shared kernel, no public method beyond the magic ones.
     *
     * @return list<Glob>
     */
    public static function globs(mixed $value, string $path): array
    {
        return array_map(
            static fn (string $pattern): Glob => new Glob($pattern),
            self::strings($value, $path, allowEmpty: true),
        );
    }

    /**
     * @param  list<string>  $roles
     * @return list<DependencyTarget>
     */
    private static function targets(mixed $value, string $path, array $roles): array
    {
        $targets = [];

        foreach (self::strings($value, $path, allowEmpty: true) as $target) {
            if (preg_match(Profile::ROLE_NAME, $target) !== 1) {
                $targets[] = DependencyTarget::glob($target);

                continue;
            }

            if (! in_array($target, $roles, true)) {
                throw new ProfileException(sprintf(
                    '%s names [%s], which is not a role. Roles: %s. To name classes rather than a role, use a glob such as "App\Support\*".',
                    $path,
                    $target,
                    implode(', ', $roles),
                ));
            }

            $targets[] = DependencyTarget::role($target);
        }

        return $targets;
    }

    /**
     * @return list<Capability>
     */
    private static function capabilities(mixed $value, string $path): array
    {
        $capabilities = [];

        foreach (self::strings($value, $path, allowEmpty: false) as $name) {
            $parsed = Capability::parse($name);

            if ($parsed === []) {
                throw new ProfileException(sprintf(
                    '%s has an unknown capability [%s]. Use any of: %s.',
                    $path,
                    $name,
                    implode(', ', Capability::names()),
                ));
            }

            foreach ($parsed as $capability) {
                if (! in_array($capability, $capabilities, true)) {
                    $capabilities[] = $capability;
                }
            }
        }

        return $capabilities;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $path, bool $allowEmpty): array
    {
        $list = is_string($value) ? [$value] : $value;

        if (! is_array($list) || ! array_is_list($list) || (! $allowEmpty && $list === [])) {
            throw new ProfileException(sprintf('%s must be a string or a list of strings.', $path));
        }

        $strings = [];

        foreach ($list as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new ProfileException(sprintf('%s must be a string or a list of strings.', $path));
            }

            $strings[] = trim($item);
        }

        return $strings;
    }

    /**
     * @param  array<mixed>  $definition
     * @param  list<string>  $known
     */
    private static function rejectUnknownKeys(array $definition, array $known, string $path): void
    {
        $unknown = array_diff(array_map(strval(...), array_keys($definition)), $known);

        if ($unknown !== []) {
            throw new ProfileException(sprintf(
                '%s has an unknown key [%s]. Use any of: %s.',
                $path,
                implode(', ', $unknown),
                implode(', ', $known),
            ));
        }
    }
}
