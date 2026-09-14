<?php

declare(strict_types=1);

/**
 * Sloppy's Pest plugin: three functions, autoloaded.
 *
 * They are guarded and global because that is what a Pest test file expects to
 * find already defined, and they live outside `src/` because Composer loads
 * this file on every request of every application that installs the package --
 * so it may cost nothing but a function_exists check until something calls it.
 *
 * ```php
 * it('has zero AI slop on the current branch', function (): void {
 *     expectCleanSloppyDiff('main');
 * });
 * ```
 */

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Testing\SloppyAssertions;

if (! function_exists('expectCleanSloppyDiff')) {
    /**
     * Fail the test if the working tree introduced findings against `$base`.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    function expectCleanSloppyDiff(
        string $base = 'main',
        ?string $failOn = null,
        array $paths = [],
        array $rules = [],
        ?int $minConfidence = null,
        ?string $project = null,
    ): DiffReport {
        return SloppyAssertions::cleanDiff($base, $failOn, $paths, $rules, $minConfidence, $project);
    }
}

if (! function_exists('expectCleanSloppyScan')) {
    /**
     * Fail the test if the analysed paths contain findings at all.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    function expectCleanSloppyScan(
        ?string $failOn = null,
        array $paths = [],
        array $rules = [],
        ?int $minConfidence = null,
        ?string $project = null,
    ): AnalysisResult {
        return SloppyAssertions::cleanScan($failOn, $paths, $rules, $minConfidence, $project);
    }
}

if (! function_exists('expectSloppyScoreAtLeast')) {
    /**
     * Fail the test if the slop score has fallen below a floor.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    function expectSloppyScoreAtLeast(
        int $minimum,
        array $paths = [],
        array $rules = [],
        ?int $minConfidence = null,
        ?string $project = null,
    ): AnalysisResult {
        return SloppyAssertions::scoreAtLeast($minimum, $paths, $rules, $minConfidence, $project);
    }
}
