<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Coverage\CoverageMap;

it('returns a ratio for a known file', function (): void {
    expect((new CoverageMap(['app/Importer.php' => 0.62]))->forFile('app/Importer.php'))->toBe(0.62);
});

it('returns null for a file it has never heard of', function (): void {
    // Null rather than zero: an unmeasured file must neither be promoted nor
    // demoted, and 0.0 would promote every file the report does not mention --
    // including every interface and every excluded directory.
    expect((new CoverageMap(['app/A.php' => 1.0]))->forFile('app/B.php'))->toBeNull();
});

it('matches regardless of slash direction', function (): void {
    expect((new CoverageMap(['app\Orders\Importer.php' => 0.5]))->forFile('app/Orders/Importer.php'))->toBe(0.5)
        ->and((new CoverageMap(['app/Orders/Importer.php' => 0.5]))->forFile('app\Orders\Importer.php'))->toBe(0.5);
});

it('knows when it has nothing', function (): void {
    expect(CoverageMap::empty()->isEmpty())->toBeTrue()
        ->and(CoverageMap::empty()->forFile('app/A.php'))->toBeNull()
        ->and(CoverageMap::empty()->sourcePath())->toBeNull()
        ->and(CoverageMap::empty()->generatedAt())->toBeNull();
});

it('remembers where it came from and when', function (): void {
    // Reported rather than acted on: a stale report silently reorders a
    // review, so the reader is told and left to judge.
    $map = new CoverageMap(['app/A.php' => 1.0], 'build/logs/clover.xml', 1700000000);

    expect($map->sourcePath())->toBe('build/logs/clover.xml')
        ->and($map->generatedAt())->toBe(1700000000);
});
