<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\HealthOptions;
use Heyosseus\Sloppy\Runner\HealthRunner;
use Heyosseus\Sloppy\Sloppy;

/**
 * `php artisan sloppy:health` -- the snapshot the Filament widget and the
 * NativePHP menu bar read, on the command line.
 *
 * Running it warms the cache those surfaces share, which is why a scheduled
 * `sloppy:health --fresh` is the recommended way to keep a dashboard current
 * without making a page render wait for an analysis.
 */
final class SloppyHealthCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy:health
        {--path=* : Analyse these paths instead of the configured ones}
        {--rule=* : Count only these rule IDs}
        {--min-confidence= : Drop findings below this confidence (0-100)}
        {--json : Emit the snapshot as JSON}
        {--fresh : Re-analyse instead of reading the cached snapshot}
        {--top= : How many ranked findings to include}';

    protected $description = 'Report the project\'s score and what is dragging it down';

    public function handle(Sloppy $sloppy): int
    {
        return $this->runWith(fn (LaravelRunnerOutput $output): ExitCode => (new HealthRunner)->run($sloppy, new HealthOptions(
            paths: $this->stringListOption('path'),
            rules: $this->stringListOption('rule'),
            minConfidence: $this->intOption('min-confidence'),
            json: $this->boolOption('json'),
            fresh: $this->boolOption('fresh'),
            top: $this->intOption('top'),
        ), $output));
    }
}
