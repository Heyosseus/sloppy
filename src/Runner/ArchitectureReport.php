<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Architecture\ArchitectureSnapshot;
use Heyosseus\Sloppy\Architecture\Boundaries;
use Heyosseus\Sloppy\Architecture\Capability;
use Heyosseus\Sloppy\Architecture\DependencyTarget;
use Heyosseus\Sloppy\Architecture\Glob;
use Heyosseus\Sloppy\Architecture\Policy;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Architecture\Role;
use Heyosseus\Sloppy\Architecture\RoleMatch;
use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Output\OutputFormat;

/**
 * The architecture as Sloppy reads it, in words or JSON: every role with its
 * classes, the policies and boundaries in force, or one class explained.
 *
 * Shared by `sloppy architecture` and the MCP tool, so a person and an agent
 * asking the same question get the same answer.
 */
final readonly class ArchitectureReport
{
    private const int EXAMPLES = 3;

    public function overview(ArchitectureSnapshot $snapshot, OutputFormat $format): string
    {
        $byRole = $snapshot->classesByRole();
        $unclassified = $snapshot->unclassified();

        return $format === OutputFormat::Json
            ? $this->overviewJson($snapshot->profile(), $byRole, $unclassified)
            : $this->overviewText($snapshot->profile(), $byRole, $unclassified, count($snapshot->roles));
    }

    /**
     * Roles the project wrote that match nothing: almost always a matcher
     * with a typo in it.
     *
     * @return list<Role>
     */
    public function emptyRoles(ArchitectureSnapshot $snapshot): array
    {
        $byRole = $snapshot->classesByRole();

        return array_values(array_filter(
            $snapshot->profile()->roles,
            static fn (Role $role): bool => $role->origin === $snapshot->profile()->source && $byRole[$role->name] === [],
        ));
    }

    /**
     * An exact fully qualified name, or else every class with that short name.
     *
     * @return list<ClassSummary>
     */
    public function find(ArchitectureSnapshot $snapshot, string $name): array
    {
        $name = ltrim(trim($name), '\\');
        $exact = $snapshot->index->class($name);

        if ($exact instanceof ClassSummary) {
            return [$exact];
        }

        $found = array_values(array_filter(
            $snapshot->index->classes(),
            static fn (ClassSummary $summary): bool => $summary->shortName === $name,
        ));

        usort($found, static fn (ClassSummary $a, ClassSummary $b): int => $a->fqn <=> $b->fqn);

        return $found;
    }

    public function explain(ArchitectureSnapshot $snapshot, ClassSummary $summary, OutputFormat $format): string
    {
        $match = $snapshot->map->matchSummary($summary, $snapshot->index);
        $policy = $snapshot->profile()->policyFor($match->name());

        return $format === OutputFormat::Json
            ? $this->explanationJson($summary, $match, $policy)
            : $this->explanationText($summary, $match, $policy);
    }

    /**
     * @param  array<string, list<string>>  $byRole
     * @param  list<string>  $unclassified
     */
    private function overviewText(Profile $profile, array $byRole, array $unclassified, int $total): string
    {
        $width = max(12, ...array_map(mb_strlen(...), array_keys($byRole)));
        $lines = [
            sprintf(
                'Architecture: preset %s%s, %d role(s), %d declaration(s).',
                $profile->preset,
                $profile->source === Profile::SOURCE ? '' : ', from '.$profile->source,
                count($profile->roles),
                $total,
            ),
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

        if ($profile->policies !== []) {
            $lines[] = 'Policies:';

            foreach ($profile->policies as $role => $policy) {
                $lines[] = sprintf('  %s  %s (%s)', str_pad($role, $width), $policy->describe(), $policy->origin);
            }

            $lines[] = '';
        }

        if ($profile->boundaries instanceof Boundaries) {
            $lines[] = sprintf('Boundaries: %s (%s)', $profile->boundaries->describe(), $profile->boundaries->origin);
            $lines[] = '';
        }

        if ($profile->covers !== []) {
            $lines[] = sprintf('Covers: %s. A new class there that plays no role is reported by sloppy diff (SL307).', $this->patterns($profile->covers));
            $lines[] = '';
        }

        $lines[] = 'Roles are tried in this order, and a class takes the first one it matches.';
        $lines[] = 'Explain one class: sloppy architecture "App\Http\Controllers\OrderController"';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, list<string>>  $byRole
     * @param  list<string>  $unclassified
     */
    private function overviewJson(Profile $profile, array $byRole, array $unclassified): string
    {
        return $this->json([
            'schema' => 1,
            'preset' => $profile->preset,
            'source' => $profile->source,
            'roles' => array_map(fn (Role $role): array => [
                'name' => $role->name,
                'origin' => $role->origin,
                'description' => $role->description,
                'matches' => $role->matcher->describe(),
                'classes' => $byRole[$role->name],
                'policy' => $this->policyJson($profile->policyFor($role->name)),
            ], $profile->roles),
            'unclassified' => $unclassified,
            'boundaries' => $profile->boundaries instanceof Boundaries ? [
                'modules' => $profile->boundaries->modules,
                'public' => array_map(static fn (Glob $glob): string => $glob->pattern, $profile->boundaries->public),
                'shared' => array_map(static fn (Glob $glob): string => $glob->pattern, $profile->boundaries->shared),
                'origin' => $profile->boundaries->origin,
            ] : null,
            'covers' => array_map(static fn (Glob $glob): string => $glob->pattern, $profile->covers),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function policyJson(?Policy $policy): ?array
    {
        $targets = static fn (array $list): array => array_map(static fn (DependencyTarget $target): string => $target->describe(), $list);

        return $policy instanceof Policy ? [
            'may_depend_on' => $policy->mayDependOn === null ? null : $targets($policy->mayDependOn),
            'may_not_depend_on' => $targets($policy->mayNotDependOn),
            'may_not' => array_map(static fn (Capability $capability): string => $capability->value, $policy->mayNot),
            'public_methods' => $policy->publicMethods === null ? null : array_map(static fn (Glob $glob): string => $glob->pattern, $policy->publicMethods),
            'final' => $policy->final,
            'advice' => $policy->advice,
            'origin' => $policy->origin,
        ] : null;
    }

    private function explanationText(ClassSummary $summary, RoleMatch $match, ?Policy $policy): string
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

            $lines[] = $policy instanceof Policy
                ? sprintf('  Policy:   %s (%s)', $policy->describe(), $policy->origin)
                : '  Policy:   none, so SL304, SL305 and SL308 have nothing to hold it to';
        }

        return implode("\n", $lines)."\n";
    }

    private function explanationJson(ClassSummary $summary, RoleMatch $match, ?Policy $policy): string
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
            'policy' => $this->policyJson($policy),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
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
     * @param  list<Glob>  $globs
     */
    private function patterns(array $globs): string
    {
        return implode(', ', array_map(static fn (Glob $glob): string => $glob->pattern, $globs));
    }
}
