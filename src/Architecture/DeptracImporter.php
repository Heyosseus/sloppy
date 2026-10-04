<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A `deptrac.yaml` translated into an architecture profile.
 *
 * Layers become roles, collectors become matchers and the ruleset becomes
 * `may_depend_on`, which is the same allow list deptrac enforces: a layer
 * missing from the ruleset may depend on no other layer. Sloppy's matchers
 * deliberately mirror deptrac's collectors, so most of a file translates one
 * for one. What does not -- a regular expression no glob can express, a
 * collector Sloppy has no equivalent for -- is named in the notes rather than
 * guessed at.
 */
final readonly class DeptracImporter
{
    /**
     * Where deptrac looks for its configuration, newest name first.
     */
    public const array FILES = ['deptrac.yaml', 'deptrac.yml', 'depfile.yaml', 'depfile.yml'];

    /**
     * Collectors that name classes by a regular expression, and the kind each implies.
     */
    private const array CLASS_COLLECTORS = [
        'className' => null,
        'classNameRegex' => null,
        'classLike' => null,
        'class' => 'class',
        'interface' => 'interface',
        'trait' => 'trait',
    ];

    public static function find(string $basePath): ?string
    {
        foreach (self::FILES as $file) {
            if (is_file($basePath.'/'.$file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @throws ProfileException When the file cannot be read as deptrac configuration.
     */
    public function import(string $path, string $label): ProfileProposal
    {
        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new ProfileException(sprintf('%s is not valid YAML: %s', $label, $exception->getMessage()), $exception->getCode(), previous: $exception);
        }

        $config = is_array($parsed) ? ($parsed['deptrac'] ?? $parsed['parameters'] ?? $parsed) : null;

        if (! is_array($config) || ! is_array($config['layers'] ?? null)) {
            throw new ProfileException(sprintf('%s has no deptrac layers to import.', $label));
        }

        $notes = [sprintf('Imported from %s.', $label)];
        $roles = [];
        $names = [];

        /** @var mixed $layer */
        foreach ($config['layers'] as $layer) {
            $name = is_array($layer) && is_string($layer['name'] ?? null) ? $layer['name'] : null;
            $role = $name === null ? '' : ProfileInference::roleName($name);

            if ($name === null || preg_match(Profile::ROLE_NAME, $role) !== 1) {
                $notes[] = sprintf('Skipped a layer whose name cannot be a role: %s.', (string) json_encode($layer));

                continue;
            }

            $matcher = $this->layer($layer, $roles, $names, $notes);

            if ($matcher === null) {
                $notes[] = sprintf('Skipped layer %s: none of its collectors translate.', $name);

                continue;
            }

            $names[$name] = $role;
            $roles[$role] = ['description' => sprintf('Layer %s in deptrac.', $name), ...$matcher];
        }

        $profile = ['preset' => 'none', 'roles' => $roles];
        $policies = $this->ruleset($config['ruleset'] ?? [], $names, $notes);

        if ($policies !== []) {
            $profile['policies'] = $policies;
        }

        return new ProfileProposal($profile, $notes);
    }

    /**
     * A layer's collectors as one matcher: deptrac takes a class into a
     * layer when any collector matches it.
     *
     * @param  array<mixed>  $layer
     * @param  array<string, array<string, mixed>>  $roles  Layers translated so far, for `layer` collectors.
     * @param  array<string, string>  $names  Deptrac layer name => role.
     * @param  list<string>  $notes
     * @return array<string, mixed>|null
     */
    private function layer(array $layer, array $roles, array $names, array &$notes): ?array
    {
        $matchers = [];

        /** @var mixed $collector */
        foreach (is_array($layer['collectors'] ?? null) ? $layer['collectors'] : [] as $collector) {
            $matcher = is_array($collector) ? $this->collector($collector, $roles, $names, $notes) : null;

            if ($matcher !== null) {
                $matchers[] = $matcher;
            }
        }

        return match (count($matchers)) {
            0 => null,
            1 => $matchers[0],
            default => ['any' => $matchers],
        };
    }

    /**
     * @param  array<mixed>  $collector
     * @param  array<string, array<string, mixed>>  $roles
     * @param  array<string, string>  $names
     * @param  list<string>  $notes
     * @return array<string, mixed>|null
     */
    private function collector(array $collector, array $roles, array $names, array &$notes): ?array
    {
        $type = is_string($collector['type'] ?? null) ? $collector['type'] : '';
        $value = $collector['value'] ?? $collector['regex'] ?? null;
        $value = is_string($value) ? $value : null;

        $matcher = match (true) {
            array_key_exists($type, self::CLASS_COLLECTORS) => $this->classes($type, $value),
            $type === 'directory' => $this->glob('path', $value),
            $type === 'glob' && $value !== null => ['path' => $value],
            in_array($type, ['implements', 'extends', 'uses', 'attribute'], true) && $value !== null => [$type => ltrim($value, '\\')],
            $type === 'inherits' && $value !== null => ['any' => [['extends' => ltrim($value, '\\')], ['implements' => ltrim($value, '\\')]]],
            $type === 'layer' && $value !== null && isset($roles[$names[$value] ?? '']) => $this->without($roles[$names[$value] ?? '']),
            $type === 'bool' => $this->bool($collector, $roles, $names, $notes),
            default => null,
        };

        if ($matcher === null) {
            $notes[] = sprintf('Could not translate the %s collector %s; matched by nothing instead.', $type === '' ? 'untyped' : $type, (string) json_encode($collector, JSON_UNESCAPED_SLASHES));
        }

        return $matcher;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function classes(string $type, ?string $value): ?array
    {
        $glob = $this->glob('namespace', $value);
        $kind = self::CLASS_COLLECTORS[$type];

        return $glob === null || $kind === null ? $glob : ['kind' => $kind, ...$glob];
    }

    /**
     * @param  array<mixed>  $collector
     * @param  array<string, array<string, mixed>>  $roles
     * @param  array<string, string>  $names
     * @param  list<string>  $notes
     * @return array<string, mixed>|null
     */
    private function bool(array $collector, array $roles, array $names, array &$notes): ?array
    {
        $part = function (string $key) use ($collector, $roles, $names, &$notes): ?array {
            $list = is_array($collector[$key] ?? null) ? $collector[$key] : [];
            $matchers = [];

            /** @var mixed $nested */
            foreach ($list as $nested) {
                $matcher = is_array($nested) ? $this->collector($nested, $roles, $names, $notes) : null;

                if ($matcher === null) {
                    return null;
                }

                $matchers[] = $matcher;
            }

            return $matchers;
        };

        $must = $part('must');
        $mustNot = $part('must_not');

        if ($must === null || $mustNot === null || $must === []) {
            return null;
        }

        // A definition has no "all of" key, so the rest is folded into one
        // `not`: A and B and not M is A and not (not B or M).
        $first = array_shift($must);
        $first = array_key_exists('not', $first) ? ['any' => [$first]] : $first;
        $rest = [...array_map(static fn (array $matcher): array => ['not' => $matcher], $must), ...$mustNot];

        return match (count($rest)) {
            0 => $first,
            1 => [...$first, 'not' => $rest[0]],
            default => [...$first, 'not' => ['any' => $rest]],
        };
    }

    /**
     * A deptrac regular expression as a glob, when one can say the same:
     * `App\\Controller\\.*` is `*App\Controller\*`. Deptrac's patterns are
     * unanchored, so a glob gets a `*` at each end that was not anchored.
     *
     * @return array<string, string>|null
     */
    private function glob(string $key, ?string $regex): ?array
    {
        if ($regex === null || $regex === '') {
            return null;
        }

        if (preg_match('/^([#\/~@!%]).*\1[a-zA-Z]*$/s', $regex, $delimiter) === 1) {
            $regex = (string) preg_replace('/^'.preg_quote($delimiter[1], '/').'|'.preg_quote($delimiter[1], '/').'[a-zA-Z]*$/', '', $regex);
        }

        $start = str_starts_with($regex, '^') ? '' : '*';
        $end = str_ends_with($regex, '$') ? '' : '*';

        // An escaped backslash and an escaped dot are literal; set them aside
        // so that whatever is still special afterwards is a real pattern.
        $body = str_replace(['\\\\', '\\.', '.*', '.+'], ["\0", "\1", '*', '*'], trim($regex, '^$'));

        if (preg_match('/[\[\](){}|?+^$.]/', $body) === 1) {
            return null;
        }

        return [$key => (string) preg_replace('/\*+/', '*', $start.str_replace(["\0", "\1"], ['\\', '.'], $body).$end)];
    }

    /**
     * A layer referenced by another, without its description.
     *
     * @param  array<string, mixed>  $role
     * @return array<string, mixed>
     */
    private function without(array $role): array
    {
        unset($role['description']);

        return $role;
    }

    /**
     * The ruleset as allow lists. A layer the ruleset leaves out may depend
     * on no other layer, exactly as in deptrac.
     *
     * @param  array<string, string>  $names
     * @param  list<string>  $notes
     * @return array<string, array<string, list<string>>>
     */
    private function ruleset(mixed $ruleset, array $names, array &$notes): array
    {
        $ruleset = is_array($ruleset) ? $ruleset : [];
        $policies = [];

        foreach ($names as $layer => $role) {
            $allowed = [];

            /** @var mixed $target */
            foreach (is_array($ruleset[$layer] ?? null) ? $ruleset[$layer] : [] as $target) {
                $name = is_string($target) ? ltrim($target, '+') : '';

                if (isset($names[$name])) {
                    $allowed[] = $names[$name];
                } elseif ($name !== '') {
                    $notes[] = sprintf('Layer %s may depend on %s, which was not imported.', $layer, $name);
                }

                if (is_string($target) && str_starts_with($target, '+')) {
                    $notes[] = sprintf('Layer %s inherits %s\'s dependencies in deptrac (+%s); list them for %s if it needs them.', $layer, $name, $name, $role);
                }
            }

            $policies[$role] = ['may_depend_on' => array_values(array_unique($allowed))];
        }

        return $policies;
    }
}
