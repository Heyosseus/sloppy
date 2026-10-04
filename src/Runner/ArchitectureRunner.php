<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Architecture\ArchitectureMap;
use Heyosseus\Sloppy\Architecture\Role;
use Heyosseus\Sloppy\Architecture\RoleMatch;
use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Sloppy;

/**
 * Show the architecture as Sloppy reads it: which role every class plays.
 *
 * Every rule about where code belongs stands on these answers, so they have to
 * be visible. A finding that calls a class "a controller" is only as good as
 * the reason the class counts as one, and this is where that reason is shown.
 */
final readonly class ArchitectureRunner
{
    private const int EXAMPLES = 3;

    public function run(Sloppy $sloppy, ArchitectureOptions $options, RunnerOutput $output): ExitCode
    {
        $map = new ArchitectureMap($sloppy->configuration->architecture());
        $index = $this->index($sloppy);

        if ($options->class === null) {
            return $this->overview($map, $index, $options->format, $output);
        }

        return $this->explain($map, $index, $options->class, $options->format, $output);
    }

    /**
     * The configured project indexed but not analysed: roles need the index,
     * not a single rule's verdict. Files that do not parse are left out, as
     * they are from a scan.
     */
    private function index(Sloppy $sloppy): ProjectIndex
    {
        $parser = new Parser;
        $parsed = [];

        foreach ($sloppy->fileMap() as $relative => $absolute) {
            $file = $parser->parseFile($absolute, $relative);

            if ($file->isParsed()) {
                $parsed[] = $file;
            }
        }

        return ProjectIndex::build($parsed);
    }

    private function overview(ArchitectureMap $map, ProjectIndex $index, OutputFormat $format, RunnerOutput $output): ExitCode
    {
        $byRole = array_fill_keys(array_map(static fn (Role $role): string => $role->name, $map->profile->roles), []);
        $unclassified = [];

        foreach ($index->classes() as $fqn => $summary) {
            $role = $map->matchSummary($summary, $index)->name();

            if ($role === null) {
                $unclassified[] = $fqn;
            } else {
                $byRole[$role][] = $fqn;
            }
        }

        $byRole = array_map($this->sorted(...), $byRole);
        $unclassified = $this->sorted($unclassified);

        if ($format === OutputFormat::Json) {
            $output->report($this->overviewJson($map, $byRole, $unclassified), $format);

            return ExitCode::Success;
        }

        $output->report($this->overviewText($map, $byRole, $unclassified, count($index->classes())), $format);

        foreach ($map->profile->roles as $role) {
            if ($role->origin === 'sloppy.php' && $byRole[$role->name] === []) {
                $output->warn(sprintf('Role [%s] matches no class in the analysed paths. Check its matchers: %s.', $role->name, $role->matcher->describe()));
            }
        }

        return ExitCode::Success;
    }

    /**
     * @param  array<string, list<string>>  $byRole
     * @param  list<string>  $unclassified
     */
    private function overviewText(ArchitectureMap $map, array $byRole, array $unclassified, int $total): string
    {
        $profile = $map->profile;
        $width = max(12, ...array_map(mb_strlen(...), array_keys($byRole)));
        $lines = [
            sprintf('Architecture: preset %s, %d role(s), %d declaration(s).', $profile->preset, count($profile->roles), $total),
            '',
        ];

        foreach ($profile->roles as $role) {
            $classes = $byRole[$role->name];
            $lines[] = sprintf('  %s  %5d  %s', str_pad($role->name, $width), count($classes), $role->description ?? $role->matcher->describe());

            if ($classes !== []) {
                $lines[] = sprintf('  %s         e.g. %s', str_repeat(' ', $width), $this->examples($classes));
            }
        }

        $lines[] = sprintf('  %s  %5d  No role: no rule about where code belongs reports these.', str_pad('unclassified', $width), count($unclassified));
        $lines[] = '';
        $lines[] = 'Roles are tried in this order, and a class takes the first one it matches.';
        $lines[] = 'Explain one class: sloppy architecture "App\Http\Controllers\OrderController"';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, list<string>>  $byRole
     * @param  list<string>  $unclassified
     */
    private function overviewJson(ArchitectureMap $map, array $byRole, array $unclassified): string
    {
        return $this->json([
            'schema' => 1,
            'preset' => $map->profile->preset,
            'roles' => array_map(static fn (Role $role): array => [
                'name' => $role->name,
                'origin' => $role->origin,
                'description' => $role->description,
                'matches' => $role->matcher->describe(),
                'classes' => $byRole[$role->name],
            ], $map->profile->roles),
            'unclassified' => $unclassified,
        ]);
    }

    private function explain(ArchitectureMap $map, ProjectIndex $index, string $name, OutputFormat $format, RunnerOutput $output): ExitCode
    {
        $candidates = $this->find($index, $name);

        if (count($candidates) !== 1) {
            $output->error($candidates === []
                ? sprintf('No class named %s in the analysed paths.', $name)
                : sprintf('%s is ambiguous. Name one of: %s.', $name, implode(', ', array_map(static fn (ClassSummary $summary): string => $summary->fqn, $candidates))));

            return ExitCode::Error;
        }

        $summary = $candidates[0];
        $match = $map->matchSummary($summary, $index);

        $output->report($format === OutputFormat::Json
            ? $this->explanationJson($summary, $match)
            : $this->explanationText($summary, $match), $format);

        return ExitCode::Success;
    }

    /**
     * An exact fully qualified name, or else every class with that short name.
     *
     * @return list<ClassSummary>
     */
    private function find(ProjectIndex $index, string $name): array
    {
        $name = ltrim(trim($name), '\\');
        $exact = $index->class($name);

        if ($exact instanceof ClassSummary) {
            return [$exact];
        }

        $found = array_values(array_filter(
            $index->classes(),
            static fn (ClassSummary $summary): bool => $summary->shortName === $name,
        ));

        usort($found, static fn (ClassSummary $a, ClassSummary $b): int => $a->fqn <=> $b->fqn);

        return $found;
    }

    private function explanationText(ClassSummary $summary, RoleMatch $match): string
    {
        $lines = [sprintf('%s (%s:%d)', $summary->fqn, $summary->relativePath, $summary->line), ''];

        if (! $match->role instanceof Role) {
            $lines[] = '  Role:  none';
            $lines[] = '  No role matched, so no rule about where code belongs reports this class.';
        } else {
            $lines[] = sprintf('  Role:     %s, from %s', $match->role->name, $match->role->origin);
            $lines[] = sprintf('  Matched:  %s', $match->role->matcher->describe());

            foreach ($match->alsoMatched as $loser) {
                $lines[] = sprintf('  Also matched %s (%s), which is tried later.', $loser->name, $loser->origin);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function explanationJson(ClassSummary $summary, RoleMatch $match): string
    {
        return $this->json([
            'schema' => 1,
            'class' => $summary->fqn,
            'file' => $summary->relativePath,
            'line' => $summary->line,
            'role' => $match->role?->name,
            'origin' => $match->role?->origin,
            'matches' => $match->role?->matcher->describe(),
            'also_matched' => array_map(static fn (Role $role): string => $role->name, $match->alsoMatched),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  list<string>  $classes
     */
    private function examples(array $classes): string
    {
        $shown = implode(', ', array_slice($classes, 0, self::EXAMPLES));
        $more = count($classes) - self::EXAMPLES;

        return $more > 0 ? sprintf('%s and %d more', $shown, $more) : $shown;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
