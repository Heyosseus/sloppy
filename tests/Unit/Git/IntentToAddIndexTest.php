<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Git\Git;
use Heyosseus\Sloppy\Git\IntentToAddIndex;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

it('hands over a throwaway index even when there is no index to copy, and cleans it up', function (): void {
    $root = tempProject(['src/A.php' => "<?php\n"]);
    $seen = [];

    (new IntentToAddIndex(new Git($root), $root))->with(['src/A.php'], function (array $env) use (&$seen): void {
        $seen = $env;
    });

    expect(array_keys($seen))->toBe(['GIT_INDEX_FILE'])
        ->and(is_file($seen['GIT_INDEX_FILE']))->toBeFalse();

    removeTree($root);
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('runs against the real index when there is nothing to mark', function (): void {
    $root = tempProject();
    $seen = null;

    (new IntentToAddIndex(new Git($root), $root))->with([], function (array $env) use (&$seen): void {
        $seen = $env;
    });

    expect($seen)->toBe([]);

    removeTree($root);
});
