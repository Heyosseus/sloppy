<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Runner\BaselineOptions;
use Heyosseus\Sloppy\Runner\BaselineRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * `php artisan sloppy:baseline` -- accept the current findings so that only
 * new ones fail the build.
 *
 * This is how an existing codebase adopts Sloppy without a rewrite first.
 *
 * Reading options is all this class does; {@see BaselineRunner} owns the rest.
 */
final class SloppyBaselineCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy:baseline
        {--path=* : Analyse these paths instead of the configured ones}
        {--min-confidence= : Only baseline findings at or above this confidence (0-100)}
        {--rule=* : Baseline only these rule IDs}
        {--force : Overwrite an existing baseline}';

    protected $description = 'Record the current findings as accepted, so only new ones fail';

    public function handle(Sloppy $sloppy): int
    {
        $output = new LaravelRunnerOutput($this);

        try {
            $options = new BaselineOptions(
                paths: $this->stringListOption('path'),
                minConfidence: $this->intOption('min-confidence'),
                rules: $this->stringListOption('rule'),
                force: $this->boolOption('force'),
            );
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        return (new BaselineRunner)->run($sloppy, $options, $output)->value;
    }
}
