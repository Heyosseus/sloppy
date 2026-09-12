<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\DiffOptions;
use Heyosseus\Sloppy\Runner\DiffRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * `php artisan sloppy:diff` -- review what a change introduced.
 *
 * The comparison runs from a revision to the working tree, so uncommitted and
 * untracked work is included. That is deliberate: the point is to catch new
 * debt before it is committed, not after.
 *
 * Reading options is all this class does; {@see DiffRunner} owns the rest.
 */
final class SloppyDiffCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy:diff
        {base=HEAD : Revision to compare the working tree against, e.g. HEAD~1 or main}
        {--path=* : Analyse these paths instead of the configured ones}
        {--format=console : console or json}
        {--fail-on= : Lowest severity of NEW finding that fails the command, or "never"}
        {--min-confidence= : Drop findings below this confidence (0-100)}
        {--rule=* : Run only these rule IDs, e.g. --rule=SL101}
        {--explain : Include each rule\'s "why this matters" text}';

    protected $description = 'Report the findings a change introduced, relative to a git revision';

    public function handle(Sloppy $sloppy): int
    {
        $output = new LaravelRunnerOutput($this);

        try {
            $base = $this->argument('base');
            $failOn = $this->stringOption('fail-on');

            $options = new DiffOptions(
                base: is_string($base) && trim($base) !== '' ? trim($base) : 'HEAD',
                paths: $this->stringListOption('path'),
                format: OutputFormat::parse($this->stringOption('format', 'console')),
                failOn: $failOn === '' ? null : $failOn,
                minConfidence: $this->intOption('min-confidence'),
                rules: $this->stringListOption('rule'),
                explain: $this->boolOption('explain'),
            );
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        return (new DiffRunner)->run($sloppy, $options, $output)->value;
    }
}
