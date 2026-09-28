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
 *   1. penalty(r)   = Σ  weight(severity) × confidence / 100, per rule r
 *   2. units        = max(1, analysedLines / lines_per_unit)
 *   3. coverage     = min(1, |⋃ affected lines| / analysedLines)
 *   4. deduction(r) = penalty(r) / units × penalty_multiplier × (1 + coverage)
 *   5. capped(r)    = rule_cap × (1 − e^(−deduction(r) / rule_cap))
 *   6. score        = 100 − min(100, Σ capped(r))
 *
 * Step 1 makes a low-confidence finding cost less than a certain one. Step 2
 * normalises by codebase size, so a large application is not punished merely
 * for being large. Step 3 counts each affected line once: a god method inside
 * a god class is one stretch of code, not two, and summing the spans instead
 * put every application with large classes at "the whole codebase". Step 5
 * gives each rule diminishing returns: a small deduction passes through almost
 * untouched, and no single rule can take more than `rule_cap` points however
 * often it fires. Without it, one rule firing on a mature 70,000-line
 * application's long methods scored that application zero on its own.
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
        $penalties = [];

        foreach ($findings as $finding) {
            $penalties[$finding->ruleId] = ($penalties[$finding->ruleId] ?? 0.0)
                + $finding->weightedPenalty($this->config->weightFor($finding->severity));
        }

        $units = max(1.0, $analyzedLines / $this->config->linesPerUnit);

        $coverage = $analyzedLines > 0
            ? min(1.0, $this->affectedLines($findings) / $analyzedLines)
            : 0.0;

        $deduction = 0.0;

        foreach ($penalties as $penalty) {
            $deduction += $this->capped($penalty / $units * $this->config->penaltyMultiplier * (1 + $coverage));
        }

        $value = (int) round(100 - min(100.0, $deduction));
        $penalty = array_sum($penalties);

        return new Score(
            value: max(0, min(100, $value)),
            band: $this->config->bandFor($value),
            penalty: $penalty,
            density: $penalty / $units,
        );
    }

    /**
     * Diminishing returns for one rule's deduction. A cap of zero turns them
     * off, and the rule's deduction counts in full.
     */
    private function capped(float $deduction): float
    {
        $cap = $this->config->ruleCap;

        return $cap > 0.0 ? $cap * (1 - exp(-$deduction / $cap)) : $deduction;
    }

    /**
     * How many distinct lines the findings cover between them.
     *
     * @param  list<Finding>  $findings
     */
    private function affectedLines(array $findings): int
    {
        $spans = [];

        foreach ($findings as $finding) {
            $start = $finding->location->line;
            $spans[$finding->location->relativePath][] = [$start, $start + $finding->location->affectedLines() - 1];
        }

        $total = 0;

        foreach ($spans as $ranges) {
            sort($ranges);
            $reached = PHP_INT_MIN;

            foreach ($ranges as [$start, $end]) {
                if ($end <= $reached) {
                    continue;
                }

                $total += $end - max($start, $reached + 1) + 1;
                $reached = $end;
            }
        }

        return $total;
    }
}
