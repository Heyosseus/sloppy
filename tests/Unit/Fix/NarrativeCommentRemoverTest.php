<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Fix\NarrativeCommentRemover;

function restating(string $comment, int $line, string $file = 'app/Guard.php', string $kind = 'restates'): Finding
{
    return finding(rule: 'SL109', file: $file, line: $line, fingerprint: $comment, severity: Severity::Low, metrics: ['comment' => $comment, 'kind' => $kind], name: 'Narrative Comment', category: Category::Readability);
}

const GUARD = "<?php\r\n\r\nclass Guard\r\n{\r\n    public function check(\$user): bool\r\n    {\r\n        // Check if the user exists\r\n        if (\$user) {\r\n            return true; // return true\r\n        }\r\n\r\n        # Return false\r\n        return false;\r\n    }\r\n}\r\n";

it('removes only restating comments, only when they have the line to themselves', function (): void {
    $root = tempProject(['app/Guard.php' => GUARD]);

    $removed = (new NarrativeCommentRemover)->remove($root, [
        restating('Check if the user exists', 7),
        restating('Return false', 12),
        // Shares its line with code: left alone.
        restating('return true', 9),
        // The file changed since the scan and line 3 no longer holds it.
        restating('Something else', 3),
        // A different rule entirely.
        finding(file: 'app/Guard.php', line: 5),
    ]);

    expect(array_map(static fn (Finding $finding): int => $finding->location->line, $removed))->toBe([7, 12])
        ->and(file_get_contents($root.'/app/Guard.php'))->toBe(str_replace(["        // Check if the user exists\r\n", "        # Return false\r\n"], '', GUARD));

    removeTree($root);
});

it('writes nothing on a dry run, but says what it would remove', function (): void {
    $root = tempProject(['app/Guard.php' => GUARD]);

    $removed = (new NarrativeCommentRemover)->remove($root, [restating('Check if the user exists', 7)], dryRun: true);

    expect($removed)->toHaveCount(1)
        ->and(file_get_contents($root.'/app/Guard.php'))->toBe(GUARD);

    removeTree($root);
});

it('leaves step narration to a person, and skips a file it cannot read', function (): void {
    $root = tempProject(['app/Guard.php' => GUARD]);

    expect(NarrativeCommentRemover::canRemove(restating('1. Check', 7, kind: 'step')))->toBeFalse()
        ->and(NarrativeCommentRemover::canRemove(finding(rule: 'SL109')))->toBeFalse()
        ->and((new NarrativeCommentRemover)->remove($root, [restating('Gone', 1, 'app/Missing.php')]))->toBe([]);

    removeTree($root);
});
