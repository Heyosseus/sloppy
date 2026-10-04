<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\ParsedFile;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Sloppy;

/**
 * The configured project, indexed and sorted into roles, but not analysed.
 *
 * Everything that describes the architecture rather than judging code --
 * `sloppy architecture`, its graph, the placement answer and the brief written
 * for agents -- starts here, so they all see the same roles a rule would.
 */
final readonly class ArchitectureSnapshot
{
    /**
     * @param  list<ParsedFile>  $files
     * @param  array<string, string|null>  $roles  Fully qualified name => role, sorted by name.
     */
    public function __construct(
        public ArchitectureMap $map,
        public ProjectIndex $index,
        public array $files,
        public array $roles,
    ) {}

    public static function of(Sloppy $sloppy): self
    {
        return self::fromFiles(new ArchitectureMap($sloppy->configuration->architecture()), self::parse($sloppy));
    }

    /**
     * The configured files, parsed. Files that do not parse are left out, as
     * they are from a scan.
     *
     * @return list<ParsedFile>
     */
    public static function parse(Sloppy $sloppy): array
    {
        $parser = new Parser;
        $parsed = [];

        foreach ($sloppy->fileMap() as $relative => $absolute) {
            $file = $parser->parseFile($absolute, $relative);

            if ($file->isParsed()) {
                $parsed[] = $file;
            }
        }

        return $parsed;
    }

    /**
     * @param  list<ParsedFile>  $files
     */
    public static function fromFiles(ArchitectureMap $map, array $files): self
    {
        $index = ProjectIndex::build($files);
        $roles = array_map(
            static fn (ClassSummary $summary): ?string => $map->matchSummary($summary, $index)->name(),
            $index->classes(),
        );

        ksort($roles);

        return new self($map, $index, $files, $roles);
    }

    public function profile(): Profile
    {
        return $this->map->profile;
    }

    /**
     * The classes in each role, in profile order, every role listed.
     *
     * @return array<string, list<string>>
     */
    public function classesByRole(): array
    {
        $byRole = array_fill_keys(array_map(static fn (Role $role): string => $role->name, $this->map->profile->roles), []);

        foreach ($this->roles as $fqn => $role) {
            if ($role !== null) {
                $byRole[$role][] = $fqn;
            }
        }

        return $byRole;
    }

    /**
     * @return list<string>
     */
    public function unclassified(): array
    {
        return array_keys(array_filter($this->roles, static fn (?string $role): bool => $role === null));
    }

    public function roleOf(string $fqn): ?string
    {
        return $this->roles[$fqn] ?? null;
    }

    /**
     * Where a role's classes live: their namespaces, most used first.
     *
     * @return array<string, int> Namespace => classes in it.
     */
    public function namespacesOf(string $role): array
    {
        $namespaces = [];

        foreach ($this->classesByRole()[$role] ?? [] as $fqn) {
            $position = strrpos($fqn, '\\');
            $namespace = $position === false ? '' : substr($fqn, 0, $position);
            $namespaces[$namespace] = ($namespaces[$namespace] ?? 0) + 1;
        }

        arsort($namespaces);

        return $namespaces;
    }

    /**
     * The directory a role's classes are kept in most often, relative to the project.
     */
    public function directoryOf(string $role): ?string
    {
        $directories = [];

        foreach ($this->classesByRole()[$role] ?? [] as $fqn) {
            $path = $this->index->class($fqn)?->relativePath;

            if ($path !== null) {
                $directory = dirname($path);
                $directories[$directory] = ($directories[$directory] ?? 0) + 1;
            }
        }

        arsort($directories);

        return array_key_first($directories);
    }

    /**
     * The word most of a role's class names end in -- `Action`,
     * `Controller` -- when at least half of them, and two or more, share it.
     */
    public function suffixOf(string $role): ?string
    {
        $classes = $this->classesByRole()[$role] ?? [];
        $suffixes = [];

        foreach ($classes as $fqn) {
            if (preg_match('/[A-Z][a-z0-9]+$/', $fqn, $match) === 1) {
                $suffixes[$match[0]] = ($suffixes[$match[0]] ?? 0) + 1;
            }
        }

        arsort($suffixes);
        $suffix = array_key_first($suffixes);

        return $suffix !== null && $suffixes[$suffix] >= 2 && count($classes) <= $suffixes[$suffix] * 2 ? $suffix : null;
    }
}
