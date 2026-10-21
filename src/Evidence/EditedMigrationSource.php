<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Evidence;

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Location;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\SourceKind;
use Heyosseus\Sloppy\Analysis\SourceKinds;
use Heyosseus\Sloppy\Contracts\EvidenceSource;
use Heyosseus\Sloppy\Git\ChangedFile;

/**
 * SL214 -- this change edited a migration that already existed.
 *
 * Asked to add a column, an agent opens the migration that created the table
 * and adds it there. On the agent's machine `migrate:fresh` makes it look
 * right. Everywhere the migration has already run -- staging, production, a
 * colleague's laptop -- it never runs again, and the column never appears.
 *
 * Only a comparison can tell an edited migration from a new one, so this is
 * evidence rather than a rule: a scan never reports it. It reads every file
 * git says changed, not just the analysed ones, so it still works where
 * `exclude` keeps migrations out of the scan.
 */
final readonly class EditedMigrationSource implements EvidenceSource
{
    public function __construct(private SourceKinds $kinds) {}

    public function id(): string
    {
        return 'SL214';
    }

    public function name(): string
    {
        return 'Edited Migration';
    }

    public function description(): string
    {
        return 'Flags a migration that existed at the base revision and was modified or renamed by this change.';
    }

    public function explanation(): string
    {
        return 'Laravel records which migrations have run by file name and never runs one twice. An edit to one '
            .'that already ran anywhere -- production, staging, a teammate\'s database -- is never applied there; '
            .'a renamed one runs a second time. The schema quietly forks.';
    }

    public function category(): Category
    {
        return Category::Laravel;
    }

    public function severity(): Severity
    {
        return Severity::High;
    }

    public function evidence(EvidenceContext $context): iterable
    {
        // The analysed changes carry their hunks, so the finding can sit on
        // the first edited line; every other change is a path and a status.
        $analysed = [];

        foreach ($context->changedFiles as $file) {
            $analysed[$file->relativePath] = $file;
        }

        foreach ($context->repositoryChanges() as $file) {
            $file = $analysed[$file->relativePath] ?? $file;

            if (! in_array($file->status, ['modified', 'renamed'], true) || ! str_ends_with($file->relativePath, '.php')) {
                continue;
            }

            if ($this->kinds->of($file->previousPath ?? $file->relativePath) !== SourceKind::Migration) {
                continue;
            }

            yield $this->finding($file);
        }
    }

    private function finding(ChangedFile $file): Finding
    {
        $renamed = $file->status === 'renamed';
        $name = basename($file->relativePath, '.php');

        return new Finding(
            ruleId: $this->id(),
            ruleName: $this->name(),
            category: $this->category(),
            severity: $this->severity(),
            confidence: $renamed ? 80 : 85,
            location: new Location(relativePath: $file->relativePath, line: max(1, $file->hunks[0]->startLine ?? 1)),
            message: $renamed
                ? sprintf('Migration %s was renamed from %s; anywhere the old name already ran, it will run again.', $name, basename((string) $file->previousPath, '.php'))
                : sprintf('Migration %s already existed and this change edits it; anywhere it already ran, the edit will never be applied.', $name),
            explanation: $this->explanation(),
            suggestion: 'Put the change in a new migration (php artisan make:migration) and leave this one as it was. '
                .'If it has genuinely never run outside your machine, say so in review.',
            fingerprint: $renamed ? (string) $file->previousPath : $file->relativePath,
            metrics: ['status' => $file->status],
        );
    }
}
