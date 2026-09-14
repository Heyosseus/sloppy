<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Testing\SloppyAssertionFailed;
use Heyosseus\Sloppy\Testing\SloppyAssertions;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * Run a closure with the working directory somewhere else, because the
 * expectations locate the project the same way the binary does.
 *
 * @template T
 *
 * @param  callable(): T  $work
 * @return T
 */
function inProject(string $root, callable $work): mixed
{
    $previous = (string) getcwd();
    chdir($root);

    try {
        return $work();
    } finally {
        chdir($previous);
    }
}

it('passes on a project with nothing to report', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'sloppy.php' => "<?php\n\nreturn ['paths' => ['src']];\n",
        'src/Fine.php' => "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n",
    ]);

    $result = inProject($root, static fn (): AnalysisResult => expectCleanSloppyScan());

    expect($result)->toBeInstanceOf(AnalysisResult::class)
        ->and($result->count())->toBe(0);

    removeTree($root);
});

it('fails with the file, the line and the rule when a scan finds something', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'sloppy.php' => "<?php\n\nreturn ['paths' => ['src']];\n",
        'src/Bad.php' => godMethodSource(),
    ]);

    inProject($root, static function (): void {
        expect(static fn (): AnalysisResult => expectCleanSloppyScan())
            ->toThrow(SloppyAssertionFailed::class, '1 finding(s) in src');
    });

    inProject($root, static function (): void {
        try {
            expectCleanSloppyScan();
        } catch (SloppyAssertionFailed $failure) {
            expect($failure->getMessage())->toContain('src/Bad.php:5')
                ->and($failure->getMessage())->toContain('SL101 God Method (high)');

            return;
        }

        throw new RuntimeException('The expectation should have failed.');
    });

    removeTree($root);
});

it('respects the threshold it is given', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'sloppy.php' => "<?php\n\nreturn ['paths' => ['src']];\n",
        'src/Bad.php' => godMethodSource(),
    ]);

    inProject($root, static function (): void {
        // SL101 is high, so a critical-only threshold passes...
        expect(expectCleanSloppyScan(failOn: 'critical'))->toBeInstanceOf(AnalysisResult::class);

        // ...and "never" means every finding counts, because a test that asked
        // for clean and was told "nothing fails the build" has been answered
        // with something other than what it asked.
        expect(static fn (): AnalysisResult => expectCleanSloppyScan(failOn: 'never'))
            ->toThrow(SloppyAssertionFailed::class);
    });

    removeTree($root);
});

it('narrows to the paths and rules it was given', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'sloppy.php' => "<?php\n\nreturn ['paths' => ['src']];\n",
        'src/Bad.php' => godMethodSource(),
        'src/Fine.php' => "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n",
    ]);

    inProject($root, static function (): void {
        expect(expectCleanSloppyScan(paths: ['src/Fine.php']))->toBeInstanceOf(AnalysisResult::class)
            ->and(expectCleanSloppyScan(rules: ['SL107']))->toBeInstanceOf(AnalysisResult::class)
            ->and(expectCleanSloppyScan(failOn: 'critical', minConfidence: 100))->toBeInstanceOf(AnalysisResult::class);
    });

    removeTree($root);
});

it('holds a floor under the score', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'sloppy.php' => "<?php\n\nreturn ['paths' => ['src']];\n",
        'src/Bad.php' => godMethodSource(),
    ]);

    inProject($root, static function (): void {
        expect(expectSloppyScoreAtLeast(10))->toBeInstanceOf(AnalysisResult::class)
            ->and(static fn (): AnalysisResult => expectSloppyScoreAtLeast(100))
            ->toThrow(SloppyAssertionFailed::class, 'below the required 100');
    });

    removeTree($root);
});

it('takes a project path instead of the working directory', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'sloppy.php' => "<?php\n\nreturn ['paths' => ['src']];\n",
        'src/Fine.php' => "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n",
    ]);

    expect(expectCleanSloppyScan(project: $root))->toBeInstanceOf(AnalysisResult::class);

    removeTree($root);
});

it('refuses to compare outside a git repository', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Fine.php' => "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n",
    ]);

    expect(static fn (): DiffReport => expectCleanSloppyDiff(project: $root))
        ->toThrow(SloppyAssertionFailed::class, 'is not a git repository');

    removeTree($root);
});

it('passes on a branch that introduced nothing and fails on one that did', function (): void {
    $repository = TempRepository::create()
        ->write('composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}')
        ->write('sloppy.php', "<?php\n\nreturn ['paths' => ['src'], 'fail_on' => 'high'];\n")
        ->write('src/Fine.php', "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n")
        ->commit('first');

    $report = expectCleanSloppyDiff(base: 'main', project: $repository->path);

    expect($report)->toBeInstanceOf(DiffReport::class)
        ->and($report->new)->toBe([]);

    $repository->write('src/Bad.php', godMethodSource());

    expect(static fn (): DiffReport => expectCleanSloppyDiff(base: 'main', project: $repository->path))
        ->toThrow(SloppyAssertionFailed::class, 'This change introduced 1 finding(s) against main');

    // A rule filter that excludes the new finding passes again.
    expect(expectCleanSloppyDiff(base: 'main', rules: ['SL107'], project: $repository->path))
        ->toBeInstanceOf(DiffReport::class);

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('says which revision it could not resolve', function (): void {
    $repository = TempRepository::create()
        ->write('composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}')
        ->write('src/Fine.php', "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n")
        ->commit('first');

    expect(static fn (): DiffReport => expectCleanSloppyDiff(base: 'release-9', project: $repository->path))
        ->toThrow(SloppyAssertionFailed::class, 'Revision [release-9] could not be resolved');

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('exposes the same three expectations as functions and as methods', function (): void {
    expect(function_exists('expectCleanSloppyDiff'))->toBeTrue()
        ->and(function_exists('expectCleanSloppyScan'))->toBeTrue()
        ->and(function_exists('expectSloppyScoreAtLeast'))->toBeTrue()
        ->and(method_exists(SloppyAssertions::class, 'cleanDiff'))->toBeTrue()
        ->and(method_exists(SloppyAssertions::class, 'cleanScan'))->toBeTrue()
        ->and(method_exists(SloppyAssertions::class, 'scoreAtLeast'))->toBeTrue();
});
