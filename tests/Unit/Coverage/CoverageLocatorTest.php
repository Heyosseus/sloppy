<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Coverage\CoverageLocator;

function cloverFor(string $file, int $statements, int $covered): string
{
    return sprintf(
        '<?xml version="1.0"?><coverage><project><file name="%s"><metrics statements="%d" coveredstatements="%d"/></file></project></coverage>',
        $file,
        $statements,
        $covered,
    );
}

it('prefers an explicit path over configuration and autodetection', function (): void {
    $root = tempProject([]);
    mkdir($root.'/build/logs', 0o777, true);
    file_put_contents($root.'/build/logs/clover.xml', cloverFor('app/Auto.php', 2, 1));
    file_put_contents($root.'/explicit.xml', cloverFor('app/Explicit.php', 2, 2));
    file_put_contents($root.'/configured.xml', cloverFor('app/Configured.php', 2, 2));

    $map = (new CoverageLocator($root))->locate(explicit: 'explicit.xml', configured: 'configured.xml');

    expect($map->forFile('app/Explicit.php'))->toBe(1.0)
        ->and($map->forFile('app/Configured.php'))->toBeNull()
        ->and($map->forFile('app/Auto.php'))->toBeNull();

    removeTree($root);
});

it('prefers a configured path over autodetection', function (): void {
    $root = tempProject([]);
    mkdir($root.'/build/logs', 0o777, true);
    file_put_contents($root.'/build/logs/clover.xml', cloverFor('app/Auto.php', 2, 1));
    file_put_contents($root.'/configured.xml', cloverFor('app/Configured.php', 4, 1));

    $map = (new CoverageLocator($root))->locate(configured: 'configured.xml');

    expect($map->forFile('app/Configured.php'))->toBe(0.25)
        ->and($map->forFile('app/Auto.php'))->toBeNull();

    removeTree($root);
});

it('falls back to the usual build paths', function (): void {
    $root = tempProject([]);
    mkdir($root.'/build/logs', 0o777, true);
    file_put_contents($root.'/build/logs/clover.xml', cloverFor('app/Auto.php', 4, 1));

    expect((new CoverageLocator($root))->locate()->forFile('app/Auto.php'))->toBe(0.25);

    removeTree($root);
});

it('tells cobertura from clover by content, not by filename', function (): void {
    // CI configurations name these anything at all.
    $root = tempProject([]);
    file_put_contents($root.'/coverage.xml', '<?xml version="1.0"?><coverage><packages><package><classes>'
        .'<class name="A" filename="app/A.php" line-rate="0.5"/>'
        .'</classes></package></packages></coverage>');

    expect((new CoverageLocator($root))->locate()->forFile('app/A.php'))->toBe(0.5);

    removeTree($root);
});

it('returns an empty map when nothing is there', function (): void {
    $root = tempProject([]);

    expect((new CoverageLocator($root))->locate()->isEmpty())->toBeTrue()
        ->and((new CoverageLocator($root))->locate(explicit: 'missing.xml')->isEmpty())->toBeTrue();

    removeTree($root);
});

it('accepts an absolute path', function (): void {
    $root = tempProject([]);
    file_put_contents($root.'/elsewhere.xml', cloverFor('app/A.php', 2, 2));

    expect((new CoverageLocator($root))->locate(explicit: $root.'/elsewhere.xml')->forFile('app/A.php'))->toBe(1.0);

    removeTree($root);
});
