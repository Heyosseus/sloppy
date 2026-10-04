<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Configuration\ComposerJson;
use Heyosseus\Sloppy\Sloppy;

/**
 * A first draft of a project's architecture, read off the code.
 *
 * Deterministic on purpose: the packages the project requires, the namespaces
 * its classes cluster in and the words their names end in. It picks the
 * closest preset, adds a role for every family of classes the preset does not
 * cover, and names the places it could not decide about rather than guessing.
 */
final readonly class ProfileInference
{
    /**
     * A family of classes needs this many members before it earns a role,
     * and an unclear namespace this many before it is worth asking about.
     */
    private const int FAMILY = 3;

    private const int UNCLEAR = 5;

    /**
     * Words in a namespace that say which preset a project follows.
     */
    private const array SIGNALS = ['Domain', 'Application', 'Infrastructure', 'Ports', 'Adapters', 'Actions', 'Repositories', 'Modules', 'Services'];

    /**
     * Words that name what a class is rather than what it is about. A family
     * of `*Observer` classes is a role; a family of `*Album` classes is a
     * subject, and becomes a role only if it also has a namespace of its own.
     */
    private const array ROLE_WORDS = [
        'Action', 'Adapter', 'Builder', 'Cast', 'Channel', 'Client', 'Collection', 'Command', 'Component', 'Concern',
        'Contract', 'Data', 'Dto', 'Enum', 'Event', 'Exception', 'Factory', 'Filter', 'Formatter', 'Gateway', 'Handler',
        'Importer', 'Interface', 'Job', 'Listener', 'Mail', 'Mailable', 'Manager', 'Middleware', 'Notification',
        'Observer', 'Pipeline', 'Policy', 'Presenter', 'Provider', 'Query', 'Repository', 'Request', 'Resource', 'Rule',
        'Scope', 'Seeder', 'Service', 'Strategy', 'Subscriber', 'Trait', 'Transformer', 'Validator', 'Widget',
    ];

    /**
     * @param  list<string>  $paths  The analysed paths, for `covers`.
     */
    public function __construct(
        private ProjectIndex $index,
        private ComposerJson $composer,
        private array $paths,
    ) {}

    /**
     * Read off the configured project's code alone, so a broken profile is no
     * obstacle to writing its replacement.
     */
    public static function for(Sloppy $sloppy): self
    {
        return new self(
            ProjectIndex::build(ArchitectureSnapshot::parse($sloppy)),
            new ComposerJson($sloppy->configuration->basePath),
            $sloppy->configuration->paths(),
        );
    }

    /**
     * What the code shows, before any judgement: packages, namespaces, modules.
     *
     * @return array{classes: int, packages: list<string>, namespaces: array<string, int>, modules: list<string>, suffixes: array<string, int>}
     */
    public function facts(): array
    {
        $namespaces = array_fill_keys(self::SIGNALS, 0);
        $modules = [];
        $suffixes = [];

        foreach ($this->index->classes() as $fqn => $summary) {
            $segments = explode('\\', $fqn);

            foreach (array_intersect(array_slice($segments, 0, -1), self::SIGNALS) as $segment) {
                $namespaces[$segment]++;
            }

            if (preg_match('/(?:^|\\\\)Modules\\\\([^\\\\]+)\\\\/', $fqn, $match) === 1) {
                $modules[$match[1]] = true;
            }

            $suffix = self::suffix($summary);

            if ($suffix !== null) {
                $suffixes[$suffix] = ($suffixes[$suffix] ?? 0) + 1;
            }
        }

        arsort($suffixes);
        $modules = array_keys($modules);
        sort($modules);

        return [
            'classes' => count($this->index->classes()),
            'packages' => array_values(array_filter(
                ['nwidart/laravel-modules', 'lorisleiva/laravel-actions', 'spatie/laravel-data', 'laravel/framework'],
                $this->composer->requires(...),
            )),
            'namespaces' => array_filter($namespaces),
            'modules' => $modules,
            'suffixes' => array_filter($suffixes, static fn (int $count): bool => $count >= self::FAMILY),
        ];
    }

    public function propose(): ProfileProposal
    {
        $facts = $this->facts();
        [$preset, $reason] = $this->preset($facts);
        $map = new ArchitectureMap(Profile::fromArray(['preset' => $preset]));
        $unclassified = [];
        $taken = [];

        foreach ($this->index->classes() as $summary) {
            if ($map->matchSummary($summary, $this->index)->role instanceof Role) {
                $taken[self::suffix($summary) ?? ''] = true;
            } else {
                $unclassified[] = $summary;
            }
        }

        $roles = $this->families($unclassified, $map->profile, $taken);
        $left = array_values(array_filter($unclassified, static fn (ClassSummary $summary): bool => ! isset($roles[self::roleName(self::suffix($summary) ?? '')])));
        $profile = ['preset' => $preset];

        if ($roles !== []) {
            $profile['roles'] = $roles;
        }

        // Covering paths only makes sense once nearly everything has a role:
        // otherwise the first diff after `init` is a wall of SL307.
        if ($facts['classes'] > 0 && count($left) * 10 <= $facts['classes']) {
            $profile['covers'] = array_map(static fn (string $path): string => rtrim($path, '/').'/*', $this->paths);
        }

        return new ProfileProposal($profile, [
            $reason,
            ...array_map(
                static fn (string $name, array $role): string => sprintf('Role %s: %s', $name, (string) ($role['description'] ?? '')),
                array_keys($roles),
                $roles,
            ),
            sprintf('%d of %d classes would play no role.', count($left), $facts['classes']),
        ], $this->unclear($left));
    }

    /**
     * @param  array{classes: int, packages: list<string>, namespaces: array<string, int>, modules: list<string>, suffixes: array<string, int>}  $facts
     * @return array{0: string, 1: string}
     */
    private function preset(array $facts): array
    {
        $in = static fn (string $segment): int => $facts['namespaces'][$segment] ?? 0;

        return match (true) {
            count($facts['modules']) >= 2 => ['modular', sprintf('Preset modular: %d modules (%s).', count($facts['modules']), implode(', ', $facts['modules']))],
            in_array('nwidart/laravel-modules', $facts['packages'], true) => ['modular', 'Preset modular: nwidart/laravel-modules is installed.'],
            $in('Ports') > 0 || ($in('Adapters') > 0 && $in('Domain') > 0) => ['hexagonal', 'Preset hexagonal: the code has a domain with ports and adapters.'],
            $in('Domain') > 0 && $in('Application') + $in('Infrastructure') > 0 => ['ddd', 'Preset ddd: the code has Domain, Application and Infrastructure layers.'],
            in_array('lorisleiva/laravel-actions', $facts['packages'], true) || $in('Actions') >= self::FAMILY => ['laravel-actions', 'Preset laravel-actions: the use cases live in action classes.'],
            $in('Repositories') >= 2 || ($facts['suffixes']['Repository'] ?? 0) >= self::FAMILY => ['service-repository', 'Preset service-repository: queries live in repositories.'],
            default => ['laravel', 'Preset laravel: no layering beyond Laravel\'s own was found.'],
        };
    }

    /**
     * A role for every family of three or more classes no preset role took,
     * named after the word their names end in.
     *
     * A project's roles are tried before the preset's, so a family whose
     * suffix some classified class also has is left alone: its role would
     * take that class from the role it plays now.
     *
     * @param  list<ClassSummary>  $unclassified
     * @param  array<string, true>  $taken  Suffixes of classes that already play a role.
     * @return array<string, array<string, string>>
     */
    private function families(array $unclassified, Profile $profile, array $taken): array
    {
        $families = [];

        foreach ($unclassified as $summary) {
            $suffix = self::suffix($summary);

            if ($suffix !== null) {
                $families[$suffix][] = $summary;
            }
        }

        ksort($families);
        $roles = [];

        foreach ($families as $suffix => $members) {
            $name = self::roleName($suffix);

            if (count($members) >= self::FAMILY && ! isset($taken[$suffix]) && ! $profile->role($name) instanceof Role && $this->isRole($suffix, $members)) {
                $roles[$name] = [
                    'description' => sprintf('Classes named *%s (%d when this was written).', $suffix, count($members)),
                    'suffix' => $suffix,
                ];
            }
        }

        return $roles;
    }

    /**
     * Whether a family is a role: its word says what a class is, or three
     * quarters of it live in a namespace named after it (`App\Presenters`).
     *
     * @param  list<ClassSummary>  $members
     */
    private function isRole(string $suffix, array $members): bool
    {
        if (in_array($suffix, self::ROLE_WORDS, true)) {
            return true;
        }

        $names = [$suffix, $suffix.'s', $suffix.'es'];
        $home = array_filter($members, static function (ClassSummary $summary) use ($names): bool {
            $segments = explode('\\', $summary->fqn);

            return count($segments) > 1 && in_array($segments[count($segments) - 2], $names, true);
        });

        return count($home) * 4 >= count($members) * 3;
    }

    /**
     * Namespaces still holding many classes with no role: the questions
     * `init` asks rather than answers.
     *
     * @param  list<ClassSummary>  $left
     * @return array<string, int>
     */
    private function unclear(array $left): array
    {
        $namespaces = [];

        foreach ($left as $summary) {
            $position = strrpos($summary->fqn, '\\');
            $namespace = $position === false ? '' : substr($summary->fqn, 0, $position);
            $namespaces[$namespace] = ($namespaces[$namespace] ?? 0) + 1;
        }

        arsort($namespaces);

        return array_filter($namespaces, static fn (int $count, string $namespace): bool => $count >= self::UNCLEAR && $namespace !== '', ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The last word of a class's name: `Data` in `OrderData`.
     */
    private static function suffix(ClassSummary $summary): ?string
    {
        return preg_match('/[A-Z][a-z0-9]+$/', $summary->shortName, $match) === 1 && $match[0] !== $summary->shortName ? $match[0] : null;
    }

    /**
     * `ServiceProvider` to `service-provider`.
     */
    public static function roleName(string $words): string
    {
        $spaced = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', trim($words));

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($spaced)), '-');
    }
}
