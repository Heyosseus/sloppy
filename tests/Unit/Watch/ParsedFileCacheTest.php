<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Watch\ParsedFileCache;
use Heyosseus\Sloppy\Watch\TreeState;

/**
 * A project on disk plus the two things the cache is handed each tick.
 *
 * @param  array<string, string>  $files  Relative path => contents.
 * @return array{0: string, 1: array<string, string>}
 */
function watchedProject(array $files): array
{
    $root = tempProject($files);
    $map = [];

    foreach (array_keys($files) as $relative) {
        $map[$relative] = $root.'/'.$relative;
    }

    return [$root, $map];
}

it('parses a file it has not seen before', function (): void {
    [$root, $map] = watchedProject(['app/A.php' => '<?php class A {}']);

    $project = (new ParsedFileCache)->sync(TreeState::of($map), $map);

    expect($project->files)->toHaveCount(1)
        ->and($project->files[0]->relativePath)->toBe('app/A.php')
        ->and($project->errors)->toBe([]);

    removeTree($root);
});

it('hands back the same parse when the stamp has not moved', function (): void {
    [$root, $map] = watchedProject(['app/A.php' => '<?php class A {}']);

    $cache = new ParsedFileCache;
    $state = TreeState::of($map);

    // Identity is the observable proof that nothing was re-parsed, and
    // re-parsing is the cost the whole design exists to avoid.
    expect($cache->sync($state, $map)->files[0])->toBe($cache->sync($state, $map)->files[0]);

    removeTree($root);
});

it('re-parses only the file whose stamp moved', function (): void {
    [$root, $map] = watchedProject([
        'app/A.php' => '<?php class A {}',
        'app/B.php' => '<?php class B {}',
    ]);

    $cache = new ParsedFileCache;
    $before = $cache->sync(TreeState::of($map), $map);

    file_put_contents($root.'/app/B.php', '<?php class B { public function c(): void {} }');

    $after = $cache->sync(TreeState::of($map), $map);

    expect($after->files[0])->toBe($before->files[0])
        ->and($after->files[1])->not->toBe($before->files[1]);

    removeTree($root);
});

it('forgets files that left the project', function (): void {
    [$root, $map] = watchedProject([
        'app/A.php' => '<?php class A {}',
        'app/Gone.php' => '<?php class Gone {}',
    ]);

    $cache = new ParsedFileCache;
    $cache->sync(TreeState::of($map), $map);

    unset($map['app/Gone.php']);
    $project = $cache->sync(TreeState::of($map), $map);

    expect($project->files)->toHaveCount(1)
        ->and($project->files[0]->relativePath)->toBe('app/A.php');

    removeTree($root);
});

it('separates files that will not parse from the ones that will', function (): void {
    [$root, $map] = watchedProject([
        'app/Good.php' => '<?php class Good {}',
        'app/Broken.php' => '<?php class Broken { public function',
    ]);

    $project = (new ParsedFileCache)->sync(TreeState::of($map), $map);

    expect($project->files)->toHaveCount(1)
        ->and($project->files[0]->relativePath)->toBe('app/Good.php')
        ->and($project->errors)->toHaveKey('app/Broken.php');

    removeTree($root);
});

it('parses everything again after being emptied', function (): void {
    [$root, $map] = watchedProject(['app/A.php' => '<?php class A {}']);

    $cache = new ParsedFileCache;
    $state = TreeState::of($map);
    $before = $cache->sync($state, $map);

    $cache->forget();

    expect($cache->sync($state, $map)->files[0])->not->toBe($before->files[0]);

    removeTree($root);
});
