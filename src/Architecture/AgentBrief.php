<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * The architecture, written for whoever creates the next class.
 *
 * deptrac and Pest's arch() enforce layers in CI, after the code exists. An
 * agent reads `CLAUDE.md` before it writes anything, so the cheapest place to
 * teach it the layers is there: where each role lives, what it is called,
 * what it may touch -- in the project's own vocabulary.
 */
final readonly class AgentBrief
{
    public function __construct(private ArchitectureSnapshot $snapshot) {}

    /**
     * @return list<string>
     */
    public function markdown(): array
    {
        $profile = $this->snapshot->profile();
        $lines = [
            '## Architecture of this project',
            '',
            'This project declares its architecture, and Sloppy holds new code to it. Put every new class where its role '
                .'lives. Before creating a file, ask where it belongs: `vendor/bin/sloppy architecture place "<what the class does>"`, '
                .'or the MCP tool `sloppy_place`.',
            '',
        ];

        foreach ($profile->roles as $role) {
            $lines = [...$lines, ...$this->role($role), ''];
        }

        return [...$lines, ...$this->boundaries(), ...$this->covers()];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $profile = $this->snapshot->profile();

        return [
            'preset' => $profile->preset,
            'source' => $profile->source,
            'roles' => array_map(fn (Role $role): array => [
                'name' => $role->name,
                'description' => $role->description,
                'namespace' => array_key_first($this->snapshot->namespacesOf($role->name)),
                'suffix' => $this->snapshot->suffixOf($role->name),
                'instructions' => $profile->policyFor($role->name)?->instructions() ?? [],
            ], $profile->roles),
            'boundaries' => $profile->boundaries instanceof Boundaries ? $profile->boundaries->describe() : null,
            'covers' => array_map(static fn (Glob $glob): string => $glob->pattern, $profile->covers),
        ];
    }

    /**
     * @return list<string>
     */
    private function role(Role $role): array
    {
        $namespace = array_key_first($this->snapshot->namespacesOf($role->name));
        $suffix = $this->snapshot->suffixOf($role->name);
        $where = match (true) {
            $namespace === null => 'No class plays this role yet.',
            $suffix === null => sprintf('Lives in `%s`.', $namespace),
            default => sprintf('Lives in `%s`, and its names end in `%s`.', $namespace, $suffix),
        };

        $lines = [
            sprintf('### %s', $role->name),
            '',
            trim(($role->description ?? '').' '.$where),
        ];

        foreach ($this->snapshot->profile()->policyFor($role->name)?->instructions() ?? [] as $instruction) {
            $lines[] = '- '.$instruction;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function boundaries(): array
    {
        $boundaries = $this->snapshot->profile()->boundaries;

        if (! $boundaries instanceof Boundaries) {
            return [];
        }

        $shared = implode(', ', array_map(static fn (Glob $glob): string => '`'.$glob->pattern.'`', $boundaries->shared));

        return [
            '### Module boundaries',
            '',
            sprintf(
                'Modules are %s. A module uses another only through %s%s. Never import another module\'s internals; '
                    .'if what you need is not public, add it to that module\'s public surface.',
                implode(' and ', array_map(static fn (string $pattern): string => '`'.$pattern.'`', $boundaries->modules)),
                $boundaries->public === [] ? 'nothing but the shared kernel' : 'its public surface ('.$boundaries->publicSurface().', relative to the module)',
                $shared === '' ? '' : ' and the shared kernel ('.$shared.')',
            ),
            '',
        ];
    }

    /**
     * @return list<string>
     */
    private function covers(): array
    {
        $covers = $this->snapshot->profile()->covers;

        if ($covers === []) {
            return [];
        }

        return [
            '### Where new classes go',
            '',
            sprintf(
                'Every class in %s plays one of the roles above. A new class there that plays none is reported as SL307: '
                    .'put it where a role expects it, or ask for a role to be declared for it.',
                implode(', ', array_map(static fn (Glob $glob): string => '`'.$glob->pattern.'`', $covers)),
            ),
            '',
        ];
    }
}
