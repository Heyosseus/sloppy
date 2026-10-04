<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * Everything an agent needs to turn a team's prose -- an `ARCHITECTURE.md`, a
 * wiki page, a conversation -- into a profile: the format, what the code
 * shows, and the draft `init` would write.
 *
 * Sloppy never calls a model. This is the opt-in path for a team that wants
 * one to write the profile; what it writes is a file like any other, loaded
 * through the same validation and reviewed like any other change.
 */
final readonly class ProfilePrompt
{
    public function __construct(private ProfileInference $inference) {}

    public function markdown(): string
    {
        $facts = $this->inference->facts();
        $draft = $this->inference->propose();

        return implode("\n", [
            '# Describe this project\'s architecture for Sloppy',
            '',
            'Write `'.Profile::FILE.'` in the project root: a PHP file returning one array that says what roles the '
                .'classes play, what each role may depend on and do, and which modules may see which. Base it on the '
                .'architecture the team describes, checked against what the code below shows. Sloppy reads the file '
                .'on every run and reports code that breaks it.',
            '',
            '## Rules for writing it',
            '',
            ...array_map(static fn (string $rule): string => '- '.$rule, self::RULES),
            '',
            '## The format',
            '',
            '```json',
            json_encode($this->schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            '```',
            '',
            '## What the code shows',
            '',
            sprintf('- %d classes.', $facts['classes']),
            sprintf('- Packages: %s.', $facts['packages'] === [] ? 'none that imply an architecture' : implode(', ', $facts['packages'])),
            sprintf('- Namespaces that suggest layers (classes in each): %s.', $this->counts($facts['namespaces'])),
            sprintf('- Modules: %s.', $facts['modules'] === [] ? 'none' : implode(', ', $facts['modules'])),
            sprintf('- Words class names end in (classes each): %s.', $this->counts(array_slice($facts['suffixes'], 0, 15, true))),
            sprintf('- Namespaces with many classes and no obvious role: %s.', $this->counts($draft->unclear)),
            '',
            '## The draft `sloppy architecture init` would write',
            '',
            '```php',
            rtrim($draft->php('sloppy architecture init')),
            '```',
            '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $draft = $this->inference->propose();

        return [
            'schema' => 1,
            'file' => Profile::FILE,
            'rules' => self::RULES,
            'format' => $this->schema(),
            'facts' => [...$this->inference->facts(), 'unclear' => $draft->unclear],
            'draft' => $draft->profile,
        ];
    }

    private const array RULES = [
        'Start from the closest preset and override only what differs: laravel, laravel-actions, service-repository, ddd, hexagonal, modular, or none to define every role yourself.',
        'A class plays the first role it matches, in the order written, and the project\'s roles are tried before the preset\'s. Put specific roles before general ones.',
        'Role names are lower-case words joined by hyphens. A policy may only name roles the profile defines; to name classes no role covers, use a glob such as "Illuminate\\*".',
        'Forbid only what the team actually forbids. Every policy is enforced on every run, and a rule nobody agreed to is noise.',
        'Use only the keys in the format below. Sloppy refuses an unknown key and names it.',
        'When it is written, run `vendor/bin/sloppy architecture` to check that every role matches the classes it should, and fix the profile until it does.',
    ];

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $patterns = ['oneOf' => [['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]]];
        $matcher = [
            'type' => 'object',
            'description' => 'Every key must match. Globs use * for any text, backslashes included.',
            'properties' => [
                'namespace' => [...$patterns, 'description' => 'Glob on the fully qualified name: App\\Domain\\*'],
                'path' => [...$patterns, 'description' => 'Glob on the project-relative path: app/Actions/*'],
                'suffix' => [...$patterns, 'description' => 'The short name ends with this text: Action'],
                'parent' => [...$patterns, 'description' => 'Glob on the class it extends directly'],
                'extends' => [...$patterns, 'description' => 'Glob on any ancestor the project declares'],
                'implements' => [...$patterns, 'description' => 'Glob on an interface it implements'],
                'uses' => [...$patterns, 'description' => 'Glob on a trait it uses'],
                'attribute' => [...$patterns, 'description' => 'Glob on an attribute on the class'],
                'kind' => [...$patterns, 'description' => 'class, interface, trait or enum'],
                'any' => ['type' => 'array', 'description' => 'A list of matchers, one of which must match'],
                'not' => ['type' => 'object', 'description' => 'A matcher that must not match'],
            ],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'preset' => ['enum' => Presets::names()],
                'roles' => [
                    'type' => 'object',
                    'description' => 'Role name => matcher, plus an optional description and intended_abstraction (true when the role\'s interfaces are the design). false removes a preset role.',
                    'additionalProperties' => $matcher,
                ],
                'policies' => [
                    'type' => 'object',
                    'description' => 'Role name => what classes in it may depend on and do. false removes a preset policy.',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'may_depend_on' => [...$patterns, 'description' => 'The only roles it may depend on (an allow list); roles or globs'],
                            'may_not_depend_on' => [...$patterns, 'description' => 'Roles or globs it may not depend on'],
                            'may_not' => [...$patterns, 'description' => 'Capabilities it may not use: '.implode(', ', Capability::names())],
                            'public_methods' => [...$patterns, 'description' => 'The only public methods a class in the role may declare; magic methods always may'],
                            'final' => ['type' => 'boolean', 'description' => 'Classes in the role must be final'],
                            'advice' => ['type' => 'string', 'description' => 'One sentence saying what to do instead, shown with every finding'],
                        ],
                    ],
                ],
                'boundaries' => [
                    'type' => 'object',
                    'properties' => [
                        'modules' => [...$patterns, 'description' => 'Namespace patterns with one {module}: App\\Modules\\{module}\\*'],
                        'public' => [...$patterns, 'description' => 'Globs relative to a module that other modules may use: Contracts\\*'],
                        'shared' => [...$patterns, 'description' => 'Fully qualified globs every module may use'],
                    ],
                ],
                'covers' => [...$patterns, 'description' => 'Path globs where every class must play a role; a new class there that plays none is reported'],
            ],
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function counts(array $counts): string
    {
        if ($counts === []) {
            return 'none';
        }

        return implode(', ', array_map(
            static fn (string $name, int $count): string => sprintf('%s (%d)', $name, $count),
            array_keys($counts),
            $counts,
        ));
    }
}
