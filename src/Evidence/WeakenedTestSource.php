<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Evidence;

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Location;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ast\Parser;
use Heyosseus\Sloppy\Contracts\EvidenceSource;
use Heyosseus\Sloppy\Git\ChangedFile;

/**
 * SL503 -- this change made the tests pass by making them check less.
 *
 * The fastest way to turn a red test green is to stop it asking the question:
 * delete the assertion that fails, skip the test, or replace it with
 * `assertTrue(true)`. An agent told "make the tests pass" does exactly that
 * often enough to matter, and the result is a green build that proves nothing.
 *
 * Only a comparison can see it. A test with two assertions is not wrong; a
 * test that had five yesterday might be. So this reads each changed test file
 * at the base revision and now, and compares the tests by name.
 *
 * Test files are not source roots -- the analyser never scans them -- so this
 * asks git for the change list itself rather than using the context's, which
 * has already been narrowed to the analysed paths.
 */
final readonly class WeakenedTestSource implements EvidenceSource
{
    /**
     * @param  list<string>  $paths  Directories whose PHP files are tests. Any `*Test.php` is a test wherever it is.
     */
    public function __construct(
        private array $paths = ['tests'],
    ) {}

    public function id(): string
    {
        return 'SL503';
    }

    public function name(): string
    {
        return 'Weakened Test';
    }

    public function description(): string
    {
        return 'Flags tests this change skipped, stripped of assertions, filled with assertions that cannot fail, or deleted.';
    }

    public function explanation(): string
    {
        return 'A test that checks less passes more often, which is not the same as the code working. Skipping a '
            .'failing test, deleting its assertion or replacing it with assertTrue(true) turns a known failure '
            .'into an unknown one, and the green build that follows is the evidence everyone will trust.';
    }

    public function category(): Category
    {
        return Category::Suppression;
    }

    public function severity(): Severity
    {
        return Severity::High;
    }

    public function evidence(EvidenceContext $context): iterable
    {
        $files = array_values(array_filter(
            $context->git->changedFiles($context->baseRevision),
            fn (ChangedFile $file): bool => $file->status !== 'deleted' && $this->isTest($file->relativePath),
        ));

        if ($files === []) {
            return;
        }

        $previousPaths = [];

        foreach ($files as $file) {
            if ($file->existedBefore()) {
                $previousPaths[$file->relativePath] = $file->previousPath ?? $file->relativePath;
            }
        }

        $previous = $context->git->showFiles($context->baseRevision, array_values($previousPaths));
        $parser = new Parser;
        $root = rtrim(str_replace('\\', '/', $context->basePath), '/');

        foreach ($files as $file) {
            $current = @file_get_contents($root.'/'.$file->relativePath);

            if ($current === false) {
                continue;
            }

            $now = $parser->parse($file->relativePath, $current);
            $was = isset($previousPaths[$file->relativePath]) ? $previous[$previousPaths[$file->relativePath]] ?? null : null;
            $before = $was === null ? null : $parser->parse($file->relativePath, $was);

            // A file that will not parse on either side cannot be compared,
            // and guessing would be worse than saying nothing.
            if ($now->parseError !== null || $before?->parseError !== null) {
                continue;
            }

            yield from $this->compare($file->relativePath, $before instanceof \Heyosseus\Sloppy\Ast\ParsedFile ? TestInventory::of($before) : [], TestInventory::of($now));
        }
    }

    /**
     * @param  array<string, TestBody>  $before
     * @param  array<string, TestBody>  $after
     * @return iterable<Finding>
     */
    private function compare(string $path, array $before, array $after): iterable
    {
        foreach ($after as $name => $test) {
            $old = $before[$name] ?? null;

            if ($old instanceof TestBody && $test->skipped && ! $old->skipped) {
                yield $this->finding($path, $test->line, $name, 'skipped', 90, Severity::High,
                    sprintf('%s is now skipped.', $this->label($name)),
                    'Fix the code or the test so it passes. If the test is wrong, correct what it asserts; if it '
                        .'cannot run here, say why in the skip message and in the pull request.',
                );

                continue;
            }

            // In a test that already existed this is the classic weakening:
            // the failing assertion swapped for one that cannot fail. In a new
            // test it is a weak test rather than a weakened one -- often a
            // "did not throw" check -- so it is reported, but not as loudly.
            if ($test->trivial > ($old->trivial ?? 0)) {
                yield $old instanceof TestBody
                    ? $this->finding($path, $test->line, $name, 'trivial', 95, Severity::High,
                        sprintf('%s gained an assertion that cannot fail.', $this->label($name)),
                        'Assert something about the result: the value returned, the row written, the event '
                            .'dispatched. assertTrue(true) only proves the test ran.',
                    )
                    : $this->finding($path, $test->line, $name, 'trivial', 75, Severity::Medium,
                        sprintf('%s makes an assertion that cannot fail.', $this->label($name)),
                        'Assert something about the result. If the point is that nothing throws, say so: '
                            .'expect(fn () => ...)->not->toThrow(Throwable::class), or $this->expectNotToPerformAssertions().',
                    );

                continue;
            }

            if ($old instanceof TestBody && ! $test->skipped && $test->assertions < $old->assertions) {
                yield $this->finding($path, $test->line, $name, 'assertions', 85, Severity::High,
                    sprintf('%s makes %d assertion%s, down from %d.', $this->label($name), $test->assertions, $test->assertions === 1 ? '' : 's', $old->assertions),
                    'Restore the assertions, or replace each with a check of the new behaviour. If the '
                        .'behaviour they covered was removed on purpose, say so in the pull request.',
                    ['before' => $old->assertions, 'after' => $test->assertions],
                );
            }
        }

        yield from $this->deleted($path, $before, $after);
    }

    /**
     * Tests that exist only at the base and were not replaced.
     *
     * Renaming a test is the commonest reason one disappears, and a rename
     * usually edits the body too -- the description says "twenty-six" now,
     * and so does the assertion. So a removed test counts as replaced by a
     * new test in the same file that is identical, or failing that, that
     * makes at least as many assertions that can fail. Coverage did not
     * shrink, and "deleted" would be the wrong word.
     *
     * @param  array<string, TestBody>  $before
     * @param  array<string, TestBody>  $after
     * @return iterable<Finding>
     */
    private function deleted(string $path, array $before, array $after): iterable
    {
        if ($before === []) {
            return;
        }

        $added = array_values(array_filter(
            $after,
            static fn (TestBody $test): bool => ! isset($before[$test->name]),
        ));

        foreach ($before as $name => $test) {
            if (isset($after[$name])) {
                continue;
            }

            $replacement = $this->replacementFor($test, $added);

            if ($replacement !== null) {
                unset($added[$replacement]);

                continue;
            }

            yield $this->finding($path, 1, $name, 'deleted', 60, Severity::Medium,
                sprintf('%s was deleted.', $this->label($name)),
                'If the behaviour it covered is gone, ignore this. Otherwise restore the test and make it pass.',
            );
        }
    }

    /**
     * The index of the new test that stands in for a removed one: the same
     * body if there is one, otherwise the first that asserts at least as much.
     *
     * @param  array<int, TestBody>  $added
     */
    private function replacementFor(TestBody $removed, array $added): ?int
    {
        foreach ($added as $index => $candidate) {
            if ($candidate->hash === $removed->hash) {
                return $index;
            }
        }

        $needed = $removed->assertions - $removed->trivial;

        foreach ($added as $index => $candidate) {
            if ($candidate->assertions - $candidate->trivial >= $needed) {
                return $index;
            }
        }

        return null;
    }

    private function label(string $name): string
    {
        return str_contains($name, '::') ? $name.'()' : sprintf('The test "%s"', $name);
    }

    private function isTest(string $path): bool
    {
        if (! str_ends_with($path, '.php')) {
            return false;
        }

        if (str_ends_with($path, 'Test.php')) {
            return true;
        }

        foreach ($this->paths as $directory) {
            if (str_starts_with($path, rtrim(str_replace('\\', '/', $directory), '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string|int|float|bool>  $metrics
     */
    private function finding(
        string $path,
        int $line,
        string $test,
        string $kind,
        int $confidence,
        Severity $severity,
        string $message,
        string $suggestion,
        array $metrics = [],
    ): Finding {
        return new Finding(
            ruleId: $this->id(),
            ruleName: $this->name(),
            category: $this->category(),
            severity: $severity,
            confidence: $confidence,
            location: new Location(relativePath: $path, line: $line),
            message: $message,
            explanation: $this->explanation(),
            suggestion: $suggestion,
            fingerprint: $path.':'.$test.':'.$kind,
            metrics: ['test' => $test, 'kind' => $kind, ...$metrics],
        );
    }
}
