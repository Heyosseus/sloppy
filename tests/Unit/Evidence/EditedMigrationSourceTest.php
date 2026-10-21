<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\SourceKinds;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Evidence\EditedMigrationSource;
use Heyosseus\Sloppy\Evidence\EvidenceCollector;
use Heyosseus\Sloppy\Evidence\EvidenceContext;
use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\DiffHunk;
use Heyosseus\Sloppy\Git\Git;

/**
 * @param  list<ChangedFile>  $changes  Everything git reports changed.
 */
function editedMigrations(array $changes): array
{
    $context = new EvidenceContext(
        git: new Git(__DIR__),
        basePath: __DIR__,
        baseRevision: 'main',
        changedFiles: [],
        repositoryChanges: $changes,
    );

    return iterator_to_array((new EditedMigrationSource(SourceKinds::laravel()))->evidence($context), false);
}

it('flags a migration this change edited, at the first line it changed', function (): void {
    $found = editedMigrations([
        new ChangedFile(
            relativePath: 'database/migrations/2024_01_01_000000_create_orders.php',
            status: 'modified',
            hunks: [new DiffHunk(startLine: 14, lineCount: 2)],
        ),
    ]);

    expect($found)->toHaveCount(1)
        ->and($found[0]->ruleId)->toBe('SL214')
        ->and($found[0]->location->line)->toBe(14)
        ->and($found[0]->message)->toContain('2024_01_01_000000_create_orders already existed')
        ->and($found[0]->suggestion)->toContain('make:migration');
});

it('flags a renamed migration, which will run a second time', function (): void {
    $found = editedMigrations([
        new ChangedFile(
            relativePath: 'database/migrations/2024_02_01_000000_create_orders.php',
            status: 'renamed',
            previousPath: 'database/migrations/2024_01_01_000000_create_orders.php',
        ),
    ]);

    expect($found)->toHaveCount(1)
        ->and($found[0]->location->line)->toBe(1)
        ->and($found[0]->message)->toContain('renamed from 2024_01_01_000000_create_orders')
        ->and($found[0]->fingerprint)->toBe('database/migrations/2024_01_01_000000_create_orders.php');
});

it('leaves new migrations, deletions and other files alone', function (): void {
    expect(editedMigrations([
        new ChangedFile(relativePath: 'database/migrations/2024_03_01_000000_add_note.php', status: 'added'),
        new ChangedFile(relativePath: 'database/migrations/2024_03_02_000000_add_tag.php', status: 'untracked'),
        new ChangedFile(relativePath: 'database/migrations/2024_01_01_000000_create_orders.php', status: 'deleted'),
        new ChangedFile(relativePath: 'database/migrations/README.md', status: 'modified'),
        new ChangedFile(relativePath: 'app/Models/Order.php', status: 'modified'),
        new ChangedFile(relativePath: 'database/seeders/OrderSeeder.php', status: 'modified'),
    ]))->toBeEmpty();
});

it('joins a diff only in a Laravel project', function (): void {
    expect(EvidenceCollector::fromConfiguration(Configuration::fromArray(['framework' => 'laravel'], __DIR__))->ids())
        ->toContain('SL214')
        ->and(EvidenceCollector::fromConfiguration(Configuration::fromArray(['framework' => 'none'], __DIR__))->ids())
        ->not->toContain('SL214')
        ->and(EvidenceCollector::fromConfiguration(Configuration::fromArray([
            'framework' => 'laravel',
            'rules' => ['SL214' => ['enabled' => false]],
        ], __DIR__))->ids())->not->toContain('SL214');
});
