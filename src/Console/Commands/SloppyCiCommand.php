<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\CiOptions;
use Heyosseus\Sloppy\Runner\CiRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;

/**
 * `php artisan sloppy:ci` -- one command for a pipeline step.
 *
 * The same run as `vendor/bin/sloppy ci`, for the projects whose CI already
 * speaks Artisan. Reading options is all this class does; {@see CiRunner} owns
 * every decision the environment implies.
 */
final class SloppyCiCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy:ci
        {--base= : Revision to compare against; read from the CI environment when omitted}
        {--path=* : Analyse these paths instead of the configured ones}
        {--format= : github, gitlab, json, markdown, sarif or console; the provider decides when omitted}
        {--report= : Write the machine-readable report to this file instead of standard output}
        {--fail-on= : Lowest severity that fails the step, or "never"}
        {--min-confidence= : Drop findings below this confidence (0-100)}
        {--rule=* : Run only these rule IDs, e.g. --rule=SL101}
        {--scan : Analyse the whole project instead of comparing against a revision}
        {--no-summary : Do not write the job summary}
        {--no-baseline : Report every finding, including baselined ones}';

    protected $description = 'Analyse a change the way the surrounding CI system wants it reported';

    public function handle(Sloppy $sloppy): int
    {
        return $this->runWith(function (LaravelRunnerOutput $output) use ($sloppy): ExitCode {
            $format = $this->stringOption('format');
            $failOn = $this->stringOption('fail-on');
            $base = $this->stringOption('base');
            $report = $this->stringOption('report');

            $options = new CiOptions(
                base: $base === '' ? null : $base,
                paths: $this->stringListOption('path'),
                rules: $this->stringListOption('rule'),
                failOn: $failOn === '' ? null : $failOn,
                minConfidence: $this->intOption('min-confidence'),
                format: $format === '' ? null : OutputFormat::parse($format),
                report: $report === '' ? null : $report,
                summary: ! $this->boolOption('no-summary'),
                scan: $this->boolOption('scan'),
                noBaseline: $this->boolOption('no-baseline'),
            );

            return (new CiRunner(CiEnvironment::fromGlobals()))->run($sloppy, $options, $output);
        });
    }
}
