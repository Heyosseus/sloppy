<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Testing;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Ci\BaseRevision;
use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Cli\ProjectLocator;
use Heyosseus\Sloppy\Configuration\ConfigurationLoader;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Sloppy;

/**
 * Sloppy as a test, not a build step.
 *
 * ```php
 * it('has zero AI slop on the current branch', function (): void {
 *     expectCleanSloppyDiff('main');
 * });
 * ```
 *
 * A CI gate tells you about the debt after you have pushed it. A test tells
 * you while you are still in the file, in the same red-green loop as every
 * other thing that must hold about the code -- and a team that already runs
 * Pest on save gets the analyser there for free, with no new step, no new job
 * and no new place to look.
 *
 * The failure message names the file, the line and the rule, because a test
 * that fails without saying what to change is a test people delete.
 */
final readonly class SloppyAssertions
{
    /**
     * Assert that the working tree introduced nothing new against a revision.
     *
     * @param  string  $base  Revision to compare against; `origin/<base>` is tried too, for CI checkouts.
     * @param  string|null  $failOn  Lowest severity that counts, or null for the project's `fail_on` -- and every new finding when that is `never`.
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    public static function cleanDiff(
        string $base = 'main',
        ?string $failOn = null,
        array $paths = [],
        array $rules = [],
        ?int $minConfidence = null,
        ?string $project = null,
    ): DiffReport {
        $sloppy = self::sloppy($project, $paths, $rules, $minConfidence);
        $git = $sloppy->git();

        if (! $git->isAvailable() || ! $git->isRepository()) {
            self::fail(sprintf('%s is not a git repository, so there is nothing to compare against.', $sloppy->configuration->basePath));
        }

        $revision = BaseRevision::resolve($git, $base, new CiEnvironment);

        if ($revision === null) {
            self::fail(sprintf('Revision [%s] could not be resolved in this repository.', $base));
        }

        $report = $sloppy->diff($revision);
        $threshold = self::threshold($failOn, $sloppy);
        $offending = $threshold instanceof Severity ? $report->newAtOrAbove($threshold) : $report->new;

        if ($offending !== []) {
            self::fail(sprintf(
                "This change introduced %d finding(s) against %s:\n%s",
                count($offending),
                $revision,
                self::describe($offending),
            ));
        }

        return $report;
    }

    /**
     * Assert that the whole project is clean, which is a promise only a young
     * codebase or a very determined one can keep. Most projects want
     * {@see cleanDiff()} instead.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    public static function cleanScan(
        ?string $failOn = null,
        array $paths = [],
        array $rules = [],
        ?int $minConfidence = null,
        ?string $project = null,
    ): AnalysisResult {
        $sloppy = self::sloppy($project, $paths, $rules, $minConfidence);
        $result = $sloppy->analyze();
        $threshold = self::threshold($failOn, $sloppy);
        $offending = $threshold instanceof Severity ? $result->atOrAbove($threshold) : $result->findings;

        if ($offending !== []) {
            self::fail(sprintf(
                "%d finding(s) in %s:\n%s",
                count($offending),
                implode(', ', $sloppy->configuration->paths()),
                self::describe($offending),
            ));
        }

        return $result;
    }

    /**
     * Assert a floor under the slop score, for a codebase paying its debt down
     * gradually and unwilling to let it grow again in the meantime.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    public static function scoreAtLeast(
        int $minimum,
        array $paths = [],
        array $rules = [],
        ?int $minConfidence = null,
        ?string $project = null,
    ): AnalysisResult {
        $result = self::sloppy($project, $paths, $rules, $minConfidence)->analyze();

        if ($result->score->value < $minimum) {
            self::fail(sprintf(
                "The slop score is %d/100 (%s), below the required %d.\n%s",
                $result->score->value,
                $result->score->label(),
                $minimum,
                self::describe(array_slice($result->findings, 0, 5)),
            ));
        }

        return $result;
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $rules
     */
    private static function sloppy(?string $project, array $paths, array $rules, ?int $minConfidence): Sloppy
    {
        $root = (new ProjectLocator)->locate($project, (string) getcwd());
        $sloppy = new Sloppy((new ConfigurationLoader($root))->load());
        $configuration = $sloppy->configuration;

        if ($paths !== []) {
            $configuration = $configuration->withPaths($paths);
        }

        if ($minConfidence !== null) {
            $configuration = $configuration->withMinConfidence($minConfidence);
        }

        $sloppy = $sloppy->withConfiguration($configuration);

        return $rules === [] ? $sloppy : $sloppy->onlyRules($rules);
    }

    private static function threshold(?string $failOn, Sloppy $sloppy): ?Severity
    {
        if ($failOn === null) {
            return $sloppy->configuration->failOn();
        }

        return in_array(mb_strtolower(trim($failOn)), ['never', 'none', 'off'], true)
            ? null
            : Severity::parse($failOn);
    }

    /**
     * @param  list<Finding>  $findings
     */
    private static function describe(array $findings): string
    {
        return implode("\n", array_map(
            static fn (Finding $finding): string => sprintf(
                '  %s:%d  %s %s (%s) -- %s',
                $finding->location->relativePath,
                $finding->location->line,
                $finding->ruleId,
                $finding->ruleName,
                $finding->severity->value,
                $finding->message,
            ),
            $findings,
        ));
    }

    private static function fail(string $message): never
    {
        throw new SloppyAssertionFailed($message);
    }
}
