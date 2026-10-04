<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * What this project's architecture is: the roles its classes play, in the
 * order they are tried, what each role may depend on and do, and which
 * modules may see which.
 *
 * Read from `sloppy.architecture`:
 *
 *     'architecture' => [
 *         'preset' => 'laravel',
 *         'roles' => [
 *             'action' => ['namespace' => 'App\Actions\*', 'suffix' => 'Action'],
 *             'service' => false,
 *         ],
 *         'policies' => [
 *             'controller' => ['may_not' => ['db']],
 *         ],
 *         'boundaries' => ['modules' => 'App\Modules\{module}\*'],
 *         'covers' => ['app/*'],
 *     ],
 *
 * `covers` names the paths where every class is expected to play a role: a
 * change that adds a class there matching none is SL307.
 *
 * The project's own roles come first, in the order written, then whatever the
 * preset defines that the project did not. A project role or policy with the
 * preset's name replaces it; `false` removes it. Project boundaries replace
 * the preset's, and `false` turns them off.
 */
final readonly class Profile
{
    public const string ROLE_NAME = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    public const string SOURCE = 'sloppy.php';

    /**
     * A profile kept in a file of its own, in the project root.
     */
    public const string FILE = 'sloppy-architecture.php';

    private const string PATH = 'sloppy.architecture';

    private const array KEYS = ['preset', 'roles', 'policies', 'boundaries', 'covers'];

    /**
     * @param  list<Role>  $roles  In the order they are tried.
     * @param  array<string, Policy>  $policies  Keyed by role.
     * @param  list<Glob>  $covers  Globs on project-relative paths.
     * @param  string  $source  The file the project's own definitions came from.
     */
    public function __construct(
        public string $preset,
        public array $roles,
        public array $policies = [],
        public ?Boundaries $boundaries = null,
        public array $covers = [],
        public string $source = self::SOURCE,
    ) {}

    public static function default(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param  array<mixed>  $config  The `sloppy.architecture` array.
     * @param  string  $source  Where it was written, named as the origin of the project's own roles and policies.
     */
    public static function fromArray(array $config, string $source = self::SOURCE): self
    {
        $unknown = array_diff(array_map(strval(...), array_keys($config)), self::KEYS);

        if ($unknown !== []) {
            throw new ProfileException(sprintf(
                '%s has an unknown key [%s]. Use any of: %s.',
                self::PATH,
                implode(', ', $unknown),
                implode(', ', self::KEYS),
            ));
        }

        $preset = $config['preset'] ?? Presets::DEFAULT;

        if (! is_string($preset) || trim($preset) === '') {
            throw new ProfileException(sprintf('%s.preset must be a string, such as "%s".', self::PATH, Presets::DEFAULT));
        }

        $preset = mb_strtolower(trim($preset));
        $definition = Presets::definition($preset);
        $origin = 'preset '.$preset;

        $roles = self::roles(self::map($config, 'roles'), $definition['roles'] ?? [], $origin, $source);
        $names = array_map(static fn (Role $role): string => $role->name, $roles);

        return new self(
            preset: $preset,
            roles: $roles,
            policies: self::policies(self::map($config, 'policies'), $definition['policies'] ?? [], $origin, $source, $names),
            boundaries: self::boundaries($config, $definition['boundaries'] ?? null, $origin, $source),
            covers: array_key_exists('covers', $config) ? PolicyParser::globs($config['covers'], self::PATH.'.covers') : [],
            source: $source,
        );
    }

    /**
     * Whether a project-relative path is one where every class should play a role.
     */
    public function covers(string $relativePath): bool
    {
        foreach ($this->covers as $glob) {
            if ($glob->matches($relativePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the project said anything about its architecture beyond the
     * default preset -- which is when agents are told about it.
     */
    public function isDeclared(): bool
    {
        if ($this->preset !== Presets::DEFAULT || $this->policies !== [] || $this->boundaries instanceof Boundaries || $this->covers !== []) {
            return true;
        }

        foreach ($this->roles as $role) {
            if ($role->origin === $this->source) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any role's policy describes its shape.
     */
    public function constrainsShape(): bool
    {
        foreach ($this->policies as $policy) {
            if ($policy->constrainsShape()) {
                return true;
            }
        }

        return false;
    }

    public function role(string $name): ?Role
    {
        foreach ($this->roles as $role) {
            if ($role->name === $name) {
                return $role;
            }
        }

        return null;
    }

    public function policyFor(?string $role): ?Policy
    {
        return $role === null ? null : $this->policies[$role] ?? null;
    }

    /**
     * Whether any role's policy limits what it may depend on.
     */
    public function constrainsDependencies(): bool
    {
        foreach ($this->policies as $policy) {
            if ($policy->constrainsDependencies()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any role's policy forbids a capability.
     */
    public function constrainsCapabilities(): bool
    {
        foreach ($this->policies as $policy) {
            if ($policy->mayNot !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $own
     * @param  array<string, array<string, mixed>>  $preset
     * @return list<Role>
     */
    private static function roles(array $own, array $preset, string $origin, string $source): array
    {
        $roles = [];

        /** @var mixed $definition */
        foreach ($own as $name => $definition) {
            self::roleName($name, 'roles');

            if ($definition !== false) {
                $roles[$name] = RoleParser::parse($name, $definition, $source, self::PATH.'.roles.'.$name);
            }
        }

        foreach ($preset as $name => $definition) {
            if (! array_key_exists($name, $own)) {
                $roles[$name] = RoleParser::parse($name, $definition, $origin, $origin.': '.$name);
            }
        }

        return array_values($roles);
    }

    /**
     * @param  array<string, mixed>  $own
     * @param  array<string, array<string, mixed>>  $preset
     * @param  list<string>  $roles
     * @return array<string, Policy>
     */
    private static function policies(array $own, array $preset, string $origin, string $source, array $roles): array
    {
        $policies = [];

        /** @var mixed $definition */
        foreach ($own as $role => $definition) {
            self::roleName($role, 'policies');

            if ($definition === false) {
                continue;
            }

            $path = self::PATH.'.policies.'.$role;

            if (! in_array($role, $roles, true)) {
                throw new ProfileException(sprintf('%s is a policy for a role that does not exist. Roles: %s.', $path, implode(', ', $roles)));
            }

            $policies[$role] = PolicyParser::policy($role, $definition, $source, $path, $roles);
        }

        foreach ($preset as $role => $definition) {
            // A preset policy goes with its role: a project that removed or
            // replaced the role has said the preset's opinion does not apply.
            if (! array_key_exists($role, $own) && in_array($role, $roles, true)) {
                $policies[$role] = PolicyParser::policy($role, $definition, $origin, $origin.': policies.'.$role, $roles);
            }
        }

        return $policies;
    }

    /**
     * @param  array<mixed>  $config
     * @param  array<string, mixed>|null  $preset
     */
    private static function boundaries(array $config, ?array $preset, string $origin, string $source): ?Boundaries
    {
        if (array_key_exists('boundaries', $config)) {
            return $config['boundaries'] === false
                ? null
                : PolicyParser::boundaries($config['boundaries'], $source, self::PATH.'.boundaries');
        }

        return $preset === null ? null : PolicyParser::boundaries($preset, $origin, $origin.': boundaries');
    }

    /**
     * @param  array<mixed>  $config
     * @return array<string, mixed>
     */
    private static function map(array $config, string $key): array
    {
        $value = $config[$key] ?? [];

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ProfileException(sprintf(
                '%s.%s must map role names to definitions, such as [\'action\' => [...]].',
                self::PATH,
                $key,
            ));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function roleName(int|string $name, string $key): void
    {
        if (! is_string($name) || preg_match(self::ROLE_NAME, $name) !== 1) {
            throw new ProfileException(sprintf(
                '%s.%s has a role named [%s]. Role names are lower-case words joined by hyphens, such as "form-request".',
                self::PATH,
                $key,
                (string) $name,
            ));
        }
    }
}
