<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Scoring;

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Configuration\ScoreConfiguration;

/**
 * Turns a set of findings into a single deterministic number.
 *
 * The algorithm, in full:
 *
 *   1. penalty  = Σ  weight(severity) × confidence / 100
 *   2. units    = max(1, analysedLines / lines_per_unit)
 *   3. density  = penalty / units
 *   4. coverage = min(1, Σ affectedLines / analysedLines)
 *   5. score    = 100 − min(100, density × penalty_multiplier × (1 + coverage))
 *
 * Step 1 makes a low-confidence finding cost less than a certain one. Step 2
 * normalises by codebase size, so a large application is not punished merely
 * for being large. Step 4 doubles the penalty when findings blanket the whole
 * codebase and barely moves it when they are localised.
 *
 * The same input always produces the same score. No randomness, no clock, no
 * network, no model.
 */
final readonly class ScoreCalculator
{
    public function __construct(
        private ScoreConfiguration $config = new ScoreConfiguration,
    ) {}

    /**
     * @param  list<Finding>  $findings
     */
    public function calculate(array $findings, int $analyzedLines): Score
    {
        $penalty = 0.0;
        $affected = 0;

        foreach ($findings as $finding) {
            $penalty += $finding->weightedPenalty($this->config->weightFor($finding->severity));
            $affected += $finding->location->affectedLines();
        }

        $units = max(1.0, $analyzedLines / $this->config->linesPerUnit);
        $density = $penalty / $units;

        $coverage = $analyzedLines > 0
            ? min(1.0, $affected / $analyzedLines)
            : 0.0;

        $deduction = min(100.0, $density * $this->config->penaltyMultiplier * (1 + $coverage));
        $value = (int) round(100 - $deduction);

        return new Score(
            value: max(0, min(100, $value)),
            band: $this->config->bandFor($value),
            penalty: $penalty,
            density: $density,
        );
    }
}
