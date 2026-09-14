<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\FixOptions;
use Heyosseus\Sloppy\Runner\FixRunner;
use Heyosseus\Sloppy\Sloppy;

/**
 * `php artisan sloppy:fix` -- hand the fixable findings to Rector, then Pint.
 */
final class SloppyFixCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy:fix
        {--path=* : Analyse these paths instead of the configured ones}
        {--rule=* : Fix only these rule IDs, e.g. --rule=SL105}
        {--min-confidence= : Ignore findings below this confidence (0-100)}
        {--dry-run : Show what would change without writing anything}
        {--no-rector : Skip Rector, only write the configuration}
        {--no-pint : Skip the formatting pass}
        {--keep-config : Leave the generated Rector configuration in place}
        {--rector-config=rector-sloppy.php : Filename for the generated Rector configuration}';

    protected $description = 'Fix what Rector can fix, format with Pint, and report what is left';

    public function handle(Sloppy $sloppy): int
    {
        return $this->runWith(fn (LaravelRunnerOutput $output): ExitCode => (new FixRunner)->run($sloppy, new FixOptions(
            paths: $this->stringListOption('path'),
            rules: $this->stringListOption('rule'),
            minConfidence: $this->intOption('min-confidence'),
            dryRun: $this->boolOption('dry-run'),
            withRector: ! $this->boolOption('no-rector'),
            withPint: ! $this->boolOption('no-pint'),
            keepConfig: $this->boolOption('keep-config'),
            configFile: $this->stringOption('rector-config', 'rector-sloppy.php'),
        ), $output));
    }
}
