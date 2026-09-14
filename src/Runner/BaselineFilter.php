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

        $baseline = $sloppy->baselines()->load($sloppy->configuration->baselinePath());

        if (! $baseline instanceof Baseline) {
            return $result;
        }

        $partition = $sloppy->baselines()->partition($result->findings, $baseline);

        if ($partition['baselined'] !== []) {
            $output->notice(sprintf(
                '%d existing finding(s) hidden by %s.',
                count($partition['baselined']),
                basename($sloppy->configuration->baselinePath()),
            ));
        }

        return $result->withFindings($partition['new'], $sloppy->scores());
    }
}
