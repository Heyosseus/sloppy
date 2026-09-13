<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Runner\DiffRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * `php artisan sloppy:review` -- what to read first.
 *
 * `sloppy:diff` answers "did this change make it worse?". This answers the
 * question a reviewer has immediately afterwards: of everything the change
 * touched, what deserves the next ten minutes? Findings are ranked on risk --
 * severity and confidence, multiplied by how new the finding is, whether it
 * sits inside a line the change actually touched, and how much of the project
 * reaches the code it is in.
 *
 * Same analysis, same score, same exit code as `sloppy:diff`. Only the
 * presentation differs, so adopting the reading order changes no build outcome.
 */
final class SloppyReviewCommand extends SloppyDiffLikeCommand
{
    protected $signature = 'sloppy:review
        {base=HEAD : Revision to compare the working tree against, e.g. HEAD~1 or main}
        {--path=* : Analyse these paths instead of the configured ones}
        {--format=console : console, json or markdown}
        {--fail-on= : Lowest severity of NEW finding that fails the command, or "never"}
        {--min-confidence= : Drop findings below this confidence (0-100)}
        {--rule=* : Run only these rule IDs, e.g. --rule=SL101}
        {--explain-risk : Show the arithmetic behind each risk value}';

    protected $description = 'Rank what a change introduced by risk, in the order worth reading';

    public function handle(Sloppy $sloppy): int
    {
        $output = new LaravelRunnerOutput($this);

        try {
            $options = $this->diffOptionsFrom(explainRisk: $this->boolOption('explain-risk'), review: true);
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error->value;
        }

        return (new DiffRunner)->run($sloppy, $options, $output)->value;
    }
}
