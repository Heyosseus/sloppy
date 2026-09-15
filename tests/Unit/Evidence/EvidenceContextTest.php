<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Evidence\EvidenceContext;
use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\Git;

it('exposes the changed paths an evidence source needs', function (): void {
    $context = new EvidenceContext(
        git: new Git(__DIR__),
        basePath: __DIR__,
        baseRevision: 'main',
        changedFiles: [
            new ChangedFile(relativePath: 'app/A.php', status: 'modified'),
            new ChangedFile(relativePath: 'app/B.php', status: 'added'),
        ],
    );

    expect($context->changedPaths())->toBe(['app/A.php', 'app/B.php'])
        ->and($context->baseRevision)->toBe('main')
        ->and($context->basePath)->toBe(__DIR__);
});

it('has no changed paths when nothing changed', function (): void {
    // A commit that only edits a baseline file changes no analysable file at
    // all, and SL502 still has to run.
    $context = new EvidenceContext(
        git: new Git(__DIR__),
        basePath: __DIR__,
        baseRevision: 'main',
        changedFiles: [],
    );

    expect($context->changedPaths())->toBe([]);
});
