<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * Where a new class belongs: "an action that refunds an order" in, the role,
 * its namespace, its naming and what it may depend on out.
 *
 * Asked before a file is created, so an agent puts the class where the
 * architecture expects it instead of guessing and hearing about it from SL307.
 * The match is deterministic -- words of the description against each role's
 * name, its naming, its description and where its classes live -- because the
 * analyser never asks a model anything.
 */
final readonly class Placement
{
    private const array IGNORED = [
        'a', 'an', 'the', 'that', 'which', 'who', 'to', 'for', 'of', 'and', 'or', 'in', 'on', 'with', 'from',
        'by', 'it', 'its', 'this', 'new', 'one', 'class', 'be', 'is', 'are', 'will', 'should', 'some', 'my',
        'app', 'php',
    ];

    public function __construct(private ArchitectureSnapshot $snapshot) {}

    /**
     * @return array{query: string, role: array<string, mixed>|null, alternatives: list<string>, roles: list<string>}
     */
    public function answer(string $description, ?string $name = null): array
    {
        $scores = [];

        foreach ($this->snapshot->profile()->roles as $role) {
            $score = $this->score($this->words($description), $role);

            if ($score > 0) {
                $scores[$role->name] = $score;
            }
        }

        // A stable sort keeps profile order among equal scores, which is the
        // order a class would be matched in.
        uasort($scores, static fn (int $a, int $b): int => $b <=> $a);
        $ranked = array_keys($scores);
        $best = $ranked === [] ? null : $this->snapshot->profile()->role($ranked[0]);

        return [
            'query' => $description,
            'role' => $best instanceof Role ? $this->describe($best, $name) : null,
            'alternatives' => array_slice($ranked, 1, 3),
            'roles' => array_map(static fn (Role $role): string => $role->name, $this->snapshot->profile()->roles),
        ];
    }

    /**
     * @param  array{query: string, role: array<string, mixed>|null, alternatives: list<string>, roles: list<string>}  $answer
     */
    public function text(array $answer): string
    {
        $role = $answer['role'];

        if ($role === null) {
            return sprintf(
                "No role in this project's architecture matches \"%s\". Roles: %s.\nName one of them, or declare a role for this kind of class in sloppy.architecture.roles.\n",
                $answer['query'],
                implode(', ', $answer['roles']),
            );
        }

        $lines = [sprintf('"%s" belongs in the %s role: %s', $answer['query'], $this->string($role['name']), $this->string($role['description'] ?? 'no description'))];

        foreach (['class' => 'Class', 'file' => 'File', 'namespace' => 'Namespace', 'directory' => 'Directory', 'suffix' => 'Name ends in'] as $key => $label) {
            if (is_string($role[$key] ?? null)) {
                $lines[] = sprintf('- %s: %s', $label, $role[$key]);
            }
        }

        $lines[] = sprintf('- Matched by: %s', $this->string($role['matches']));

        foreach ($this->strings($role['instructions']) as $instruction) {
            $lines[] = '- '.$instruction;
        }

        $examples = $this->strings($role['examples']);
        $lines[] = $examples === [] ? '- No class plays this role yet.' : '- For example: '.implode(', ', $examples);

        if ($answer['alternatives'] !== []) {
            $lines[] = sprintf('Also close: %s.', implode(', ', $answer['alternatives']));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Role $role, ?string $name): array
    {
        $namespace = array_key_first($this->snapshot->namespacesOf($role->name));
        $directory = $this->snapshot->directoryOf($role->name);
        $suffix = $this->snapshot->suffixOf($role->name);
        $class = $name === null ? null : $this->className($name, $suffix);
        $examples = $this->snapshot->classesByRole()[$role->name] ?? [];

        return [
            'name' => $role->name,
            'description' => $role->description,
            'origin' => $role->origin,
            'matches' => $role->matcher->describe(),
            'namespace' => $namespace,
            'directory' => $directory,
            'suffix' => $suffix,
            'class' => $class === null || $namespace === null ? $class : $namespace.'\\'.$class,
            'file' => $class === null || $directory === null ? null : $directory.'/'.$class.'.php',
            'instructions' => $this->snapshot->profile()->policyFor($role->name)?->instructions() ?? [],
            'examples' => array_slice($examples, 0, 3),
        ];
    }

    /**
     * How well a description fits a role: its name and naming count three
     * times as much as the words of its description and namespace.
     *
     * @param  list<string>  $words
     */
    private function score(array $words, Role $role): int
    {
        $strong = $this->words(implode(' ', [str_replace('-', ' ', $role->name), $this->snapshot->suffixOf($role->name) ?? '']));
        $weak = $this->words(implode(' ', [$role->description ?? '', array_key_first($this->snapshot->namespacesOf($role->name)) ?? '']));
        $score = 0;

        foreach (array_unique($words) as $word) {
            $score += in_array($word, $strong, true) ? 3 : (in_array($word, $weak, true) ? 1 : 0);
        }

        return $score;
    }

    /**
     * Lower-case words, camel case split, plurals folded, filler dropped.
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        $spaced = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $text);
        $words = [];

        foreach (preg_split('/[^A-Za-z0-9]+/', mb_strtolower($spaced)) ?: [] as $word) {
            $word = mb_strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss') ? mb_substr($word, 0, -1) : $word;

            if ($word !== '' && ! in_array($word, self::IGNORED, true)) {
                $words[] = $word;
            }
        }

        return $words;
    }

    private function className(string $name, ?string $suffix): string
    {
        $name = ltrim(trim($name), '\\');

        return $suffix !== null && ! str_ends_with($name, $suffix) ? $name.$suffix : $name;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
