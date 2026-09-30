<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Baseline\Baseline;
use Heyosseus\Sloppy\Sloppy;

/**
 * Drop the findings the baseline already accepts, and say how many went.
 *
 * Two runners now need this -- `sloppy` and `sloppy ci` in scan mode -- and a
 * second copy of it is exactly the maintained duplicate
 * {@see \Heyosseus\Sloppy\Rules\Php\CopyPasteDriftRule} exists to warn about.
 */
final readonly class BaselineFilter
{
    /**
     * @param  bool  $enabled  False for `--no-baseline`, which reports everything.
     */
    public function apply(Sloppy $sloppy, AnalysisResult $result, RunnerOutput $output, bool $enabled = true): AnalysisResult
    {
        if (! $enabled) {
            return $result;
        }

        $new = $sloppy->baselines()->apply($result, $sloppy->configuration->baselinePath(), $sloppy->scores());
        $hidden = $result->count() - $new->count();

        if ($hidden > 0) {
            $output->notice(sprintf(
                '%d existing finding(s) hidden by %s.',
                $hidden,
                basename($sloppy->configuration->baselinePath()),
            ));
        }

        return $new;
    }
}
