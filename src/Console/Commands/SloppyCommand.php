<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\ScanOptions;
use Heyosseus\Sloppy\Runner\ScanRunner;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * `php artisan sloppy` -- analyse the configured paths.
 *
 * Reading options is all this class does. The analysis, the baseline handling
 * and the exit code belong to {@see ScanRunner}, which `vendor/bin/sloppy`
 * calls through a different output adapter -- so the two surfaces cannot
 * disagree about what the command means.
 */
final class SloppyCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy
        {--path=* : Analyse these paths instead of the configured ones}
        {--format=console : console or json}
        {--fail-on= : Lowest severity that fails the command, or "never"}
        {--min-confidence= : Drop findings below this confidence (0-100)}
        {--rule=* : Run only these rule IDs, e.g. --rule=SL101}
        {--explain : Include each rule\'s "why this matters" text}
        {--no-baseline : Report every finding, including baselined ones}';

    protected $description = 'Analyse the application for AI-slop code patterns';

    public function handle(Sloppy $sloppy): int
    {
        $output = new LaravelRunnerOutput($this);

        try {
            $failOn = $this->stringOption('fail-on');

            $options = new ScanOptions(
                paths: $this->stringListOption('path'),
                format: OutputFormat::parse($this->stringOption('format', 'console')),
                failOn: $failOn === '' ? null : $failOn,
                minConfidence: $this->intOption('min-confidence'),
                rules: $this->stringListOption('rule'),
                explain: $this->boolOption('explain'),
                noBaseline: $this->boolOption('no-baseline'),
            );
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        return (new ScanRunner)->run($sloppy, $options, $output)->value;
    }
}
