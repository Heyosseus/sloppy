<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

use Heyosseus\Sloppy\Ast\NodeHelper;
use InvalidArgumentException;

/**
 * Which roles depend on which, with the edges the policies forbid in red.
 *
 * Drawn from the same dependencies SL304 judges, so a red edge in the picture
 * is always a finding in the report. Classes with no role, and the framework
 * and vendor code no role covers, are left out: the picture is of the
 * architecture the project declared, not of everything it imports.
 */
final readonly class DependencyGraph
{
    public const array FORMATS = ['mermaid', 'dot', 'json'];

    private const string RED = '#d73a49';

    /**
     * @param  array<string, int>  $nodes  Role => classes in it, in profile order.
     * @param  list<RoleEdge>  $edges  Sorted by source, then target.
     */
    public function __construct(
        public array $nodes,
        public array $edges,
    ) {}

    public static function of(ArchitectureSnapshot $snapshot): self
    {
        /** @var array<string, array<string, array{count: int, forbidden: int, example: ?string}>> $edges */
        $edges = [];

        foreach ($snapshot->files as $file) {
            foreach ($file->classLikes() as $classLike) {
                $fqn = NodeHelper::className($classLike);
                $role = $fqn === null ? null : $snapshot->roleOf($fqn);

                if ($fqn === null || $role === null) {
                    continue;
                }

                $policy = $snapshot->profile()->policyFor($role);

                foreach (array_keys(DependencyScanner::references($classLike)) as $target) {
                    $targetRole = $snapshot->roleOf($target);

                    if ($targetRole === null || $targetRole === $role) {
                        continue;
                    }

                    $edge = $edges[$role][$targetRole] ?? ['count' => 0, 'forbidden' => 0, 'example' => null];
                    $edge['count']++;

                    if ($policy?->dependencyViolation($target, $targetRole) !== null) {
                        $edge['forbidden']++;
                        $edge['example'] ??= NodeHelper::baseName($fqn).' -> '.NodeHelper::baseName($target);
                    }

                    $edges[$role][$targetRole] = $edge;
                }
            }
        }

        return new self(
            array_map(count(...), $snapshot->classesByRole()),
            self::flatten($edges),
        );
    }

    public function render(string $format): string
    {
        return match ($format) {
            'mermaid' => $this->mermaid(),
            'dot' => $this->dot(),
            'json' => json_encode([
                'schema' => 1,
                'roles' => $this->nodes,
                'edges' => array_map(static fn (RoleEdge $edge): array => $edge->toArray(), $this->edges),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            default => throw new InvalidArgumentException(sprintf(
                'sloppy architecture graph writes %s, not %s.',
                implode(', ', self::FORMATS),
                $format,
            )),
        };
    }

    private function mermaid(): string
    {
        $lines = ['flowchart LR'];

        foreach ($this->nodes as $role => $classes) {
            $lines[] = sprintf('    %s["%s (%d)"]', $this->id($role), $role, $classes);
        }

        foreach ($this->edges as $position => $edge) {
            $lines[] = sprintf('    %s -->|%s| %s', $this->id($edge->from), $edge->label(), $this->id($edge->to));

            if ($edge->isForbidden()) {
                $lines[] = sprintf('    linkStyle %d stroke:%s,stroke-width:2px,color:%s', $position, self::RED, self::RED);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function dot(): string
    {
        $lines = ['digraph architecture {', '    rankdir=LR;', '    node [shape=box];'];

        foreach ($this->nodes as $role => $classes) {
            $lines[] = sprintf('    "%s" [label="%s\n%d classes"];', $role, $role, $classes);
        }

        foreach ($this->edges as $edge) {
            $lines[] = sprintf(
                '    "%s" -> "%s" [label="%s"%s];',
                $edge->from,
                $edge->to,
                $edge->label(),
                $edge->isForbidden() ? sprintf(', color="%s", fontcolor="%s"', self::RED, self::RED) : '',
            );
        }

        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * Mermaid identifiers cannot hold a hyphen, which role names can.
     */
    private function id(string $role): string
    {
        return str_replace('-', '_', $role);
    }

    /**
     * @param  array<string, array<string, array{count: int, forbidden: int, example: ?string}>>  $edges
     * @return list<RoleEdge>
     */
    private static function flatten(array $edges): array
    {
        $flat = [];

        ksort($edges);

        foreach ($edges as $from => $targets) {
            ksort($targets);

            foreach ($targets as $to => $edge) {
                $flat[] = new RoleEdge($from, $to, $edge['count'], $edge['forbidden'], $edge['example']);
            }
        }

        return $flat;
    }
}
