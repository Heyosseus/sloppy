<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Evidence;

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Location;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Architecture\ArchitectureMap;
use Heyosseus\Sloppy\Architecture\PolicyRule;
use Heyosseus\Sloppy\Architecture\Profile;
use Heyosseus\Sloppy\Architecture\Role;
use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Ast\NodeHelper;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Ast\ProjectIndex;
use Heyosseus\Sloppy\Contracts\EvidenceSource;
use Heyosseus\Sloppy\Git\ChangedFile;

/**
 * SL307 -- this change added a class that has no place in the architecture.
 *
 * Agents love a new file: `app/Services/Helpers/DataUtils.php`, a fresh
 * `Support` namespace, a `Manager` nobody asked for. Each one is a class no
 * role describes, so no policy holds it to anything and the next agent has no
 * idea what it is for.
 *
 * Only a comparison can tell a new homeless class from an old one, and old
 * ones are not this change's business -- which is why this is evidence, not a
 * rule: a scan never reports it. It runs only where the project has said
 * which paths are covered (`sloppy.architecture.covers`), so a project that
 * keeps helpers on purpose is never told otherwise.
 */
final readonly class MisplacedClassSource implements EvidenceSource, PolicyRule
{
    public function __construct(private Profile $profile) {}

    public function id(): string
    {
        return 'SL307';
    }

    public function name(): string
    {
        return 'Misplaced Class';
    }

    public function description(): string
    {
        return 'Flags a class this change adds, in a path the architecture covers, that plays no role in it.';
    }

    public function explanation(): string
    {
        return 'A class no role describes is a class no policy holds to anything. Every one added makes the '
            .'architecture a little less true, and the next person -- or agent -- copies the place it was put, '
            .'not the place it belonged.';
    }

    public function category(): Category
    {
        return Category::Dependencies;
    }

    public function severity(): Severity
    {
        return Severity::Low;
    }

    public function appliesTo(Profile $profile): bool
    {
        return $profile->covers !== [];
    }

    public function evidence(EvidenceContext $context): iterable
    {
        $index = $context->index;

        if (! $index instanceof ProjectIndex || $this->profile->covers === []) {
            return;
        }

        $files = array_values(array_filter(
            $context->changedFiles,
            fn (ChangedFile $file): bool => $file->status !== 'deleted' && $this->profile->covers($file->relativePath),
        ));

        if ($files === []) {
            return;
        }

        $existing = $this->classesBefore($context, $files);
        $map = new ArchitectureMap($this->profile);

        foreach ($index->classes() as $fqn => $summary) {
            $before = $existing[$summary->relativePath] ?? null;

            if ($before === null || isset($before[$fqn]) || $map->matchSummary($summary, $index)->role instanceof Role) {
                continue;
            }

            yield $this->finding($summary);
        }
    }

    /**
     * The classes each covered file declared at the base revision, keyed by
     * its current path. A new file declared none.
     *
     * @param  list<ChangedFile>  $files
     * @return array<string, array<string, true>>
     */
    private function classesBefore(EvidenceContext $context, array $files): array
    {
        $wanted = [];
        $classes = [];

        foreach ($files as $file) {
            $classes[$file->relativePath] = [];

            if ($file->existedBefore()) {
                $wanted[$file->relativePath] = $file->previousPath ?? $file->relativePath;
            }
        }

        $previous = $context->git->showFiles($context->baseRevision, array_values($wanted));
        $parser = new Parser;

        foreach ($wanted as $path => $previousPath) {
            $source = $previous[$previousPath] ?? null;
            $parsed = $source === null ? null : $parser->parse($previousPath, $source);

            foreach ($parsed?->classLikes() ?? [] as $classLike) {
                $fqn = NodeHelper::className($classLike);

                if ($fqn !== null) {
                    $classes[$path][$fqn] = true;
                }
            }
        }

        return $classes;
    }

    private function finding(ClassSummary $summary): Finding
    {
        $roles = array_map(static fn (Role $role): string => $role->name, $this->profile->roles);

        return new Finding(
            ruleId: $this->id(),
            ruleName: $this->name(),
            category: $this->category(),
            severity: $this->severity(),
            confidence: 75,
            location: new Location(relativePath: $summary->relativePath, line: $summary->line),
            message: sprintf(
                'New class %s plays no role in this project\'s architecture, and %s is in a path where every class should.',
                $summary->fqn,
                $summary->relativePath,
            ),
            explanation: $this->explanation(),
            suggestion: $roles === []
                ? sprintf('Declare the role this class plays in sloppy.architecture.roles (%s), or move it out of the covered paths.', $this->profile->source)
                : sprintf(
                    'Put it where one of the roles expects it (%s) -- `sloppy architecture place` says where each one lives -- or, '
                        .'if it is a new kind of class, declare its role in sloppy.architecture.roles (%s).',
                    implode(', ', $roles),
                    $this->profile->source,
                ),
            fingerprint: $summary->fqn,
            metrics: ['class' => $summary->fqn, 'kind' => $summary->kind],
        );
    }
}
