<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * What this project's architecture is: the roles its classes play, in the
 * order they are tried.
 *
 * Read from `sloppy.architecture`:
 *
 *     'architecture' => [
 *         'preset' => 'laravel',
 *         'roles' => [
 *             'action' => ['namespace' => 'App\Actions\*', 'suffix' => 'Action'],
 *             'service' => false,
 *         ],
 *     ],
 *
 * The project's own roles come first, in the order written, then whatever the
 * preset defines that the project did not. A project role with a preset
 * role's name replaces it; `false` removes it.
 */
final readonly class Profile
{
    private const string PATH = 'sloppy.architecture';

    /**
     * @param  list<Role>  $roles  In the order they are tried.
     */
    public function __construct(
        public string $preset,
        public array $roles,
    ) {}

    public static function default(): self
    {
        return self::fromArray([]);
    }

    /**
     * @param  array<mixed>  $config  The `sloppy.architecture` array.
     */
    public static function fromArray(array $config): self
    {
        $unknown = array_diff(array_map(strval(...), array_keys($config)), ['preset', 'roles']);

        if ($unknown !== []) {
            throw new ProfileException(sprintf(
                '%s has an unknown key [%s]. Use preset or roles.',
                self::PATH,
                implode(', ', $unknown),
            ));
        }

        $preset = $config['preset'] ?? Presets::DEFAULT;

        if (! is_string($preset) || trim($preset) === '') {
            throw new ProfileException(sprintf('%s.preset must be a string, such as "%s".', self::PATH, Presets::DEFAULT));
        }

        $preset = mb_strtolower(trim($preset));
        $own = $config['roles'] ?? [];

        if (! is_array($own) || ($own !== [] && array_is_list($own))) {
            throw new ProfileException(sprintf(
                '%s.roles must map role names to matchers, such as [\'action\' => [\'suffix\' => \'Action\']].',
                self::PATH,
            ));
        }

        $roles = [];

        /** @var mixed $definition */
        foreach ($own as $name => $definition) {
            $name = self::roleName($name);

            if ($definition === false) {
                continue;
            }

            $roles[$name] = RoleParser::parse($name, $definition, 'sloppy.php', self::PATH.'.roles.'.$name);
        }

        $origin = 'preset '.$preset;

        foreach (Presets::roles($preset) as $name => $definition) {
            if (! array_key_exists($name, $own)) {
                $roles[$name] = RoleParser::parse($name, $definition, $origin, $origin.': '.$name);
            }
        }

        return new self($preset, array_values($roles));
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

    private static function roleName(int|string $name): string
    {
        if (! is_string($name) || preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $name) !== 1) {
            throw new ProfileException(sprintf(
                '%s.roles has a role named [%s]. Role names are lower-case words joined by hyphens, such as "form-request".',
                self::PATH,
                (string) $name,
            ));
        }

        return $name;
    }
}
