<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Scoring;

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Configuration\RiskConfiguration;
use Heyosseus\Sloppy\Coverage\CoverageMap;

/**
 * In what order should a human read this?
 *
 *     risk = severity_weight x (confidence / 100) x novelty x proximity x reach x exposure
 *
 *     reach     = 1 + log10(1 + blast_radius) x reach_weight
 *     novelty   = new 1.0 | inherited 0.25
 *     proximity = inside a changed hunk 1.0 | elsewhere in a touched file 0.3
 *     exposure  = 1 + (1 - coverage) x exposure_weight | 1.0 when unmeasured
 *
 * This answers a different question from the slop score and does not touch it.
 * The score is a quality measure: density-normalised, baseline-compatible, and
 * about the codebase. Risk is an attention measure: absolute, change-aware, and
 * about the reader's next ten minutes. Both are displayed, because a team that
 * conflates them will chase the wrong one.
 *
 * Every factor defaults to 1.0 when it cannot be measured, which is what makes
 * the model safe to extend: a factor nobody has data for leaves the ranking
 * exactly as it was rather than silently reshuffling it.
 */
final readonly class RiskCalculator
{
    public function __construct(
        private RiskConfiguration $config = new RiskConfiguration,
        private CoverageMap $coverage = new CoverageMap,
    ) {}

    /**
     * @param  bool|null  $isNew  Null outside diff mode, where novelty is unknowable.
     * @param  bool|null  $inHunk  Null when there are no hunks to be inside.
     */
    public function for(Finding $finding, ?bool $isNew = null, ?bool $inHunk = null): Risk
    {
        [$novelty, $noveltyLabel] = match ($isNew) {
            true => [RiskConfiguration::NOVELTY_NEW, 'new'],
            false => [RiskConfiguration::NOVELTY_INHERITED, 'inherited'],
            null => [1.0, 'novelty unknown'],
        };

        [$proximity, $proximityLabel] = match ($inHunk) {
            true => [RiskConfiguration::PROXIMITY_IN_HUNK, 'in hunk'],
            false => [RiskConfiguration::PROXIMITY_NEARBY, 'nearby'],
            null => [1.0, 'whole file'],
        };

        $blastRadius = $this->blastRadiusOf($finding);
        $reach = $this->config->reachFor($blastRadius);
        $severityWeight = $this->config->weightFor($finding->severity);
        $confidence = $finding->confidence / 100;

        $covered = $this->coverage->forFile($finding->location->relativePath);
        $exposure = $this->config->exposureFor($covered);
        $exposureLabel = match (true) {
            $covered === null => 'coverage unknown',
            $covered <= 0.0 => 'untested',
            $covered >= 1.0 => 'fully covered',
            default => sprintf('%d%% covered', (int) round($covered * 100)),
        };

        return new Risk(
            value: $severityWeight * $confidence * $novelty * $proximity * $reach * $exposure,
            severityWeight: $severityWeight,
            severityLabel: $finding->severity->value,
            confidence: $confidence,
            novelty: $novelty,
            noveltyLabel: $noveltyLabel,
            proximity: $proximity,
            proximityLabel: $proximityLabel,
            reach: $reach,
            blastRadius: $blastRadius,
            exposure: $exposure,
            exposureLabel: $exposureLabel,
        );
    }

    /**
     * Findings ordered by descending risk, ties broken so the order is total.
     *
     * @param  list<Finding>  $findings
     * @return list<array{finding: Finding, risk: Risk}>
     */
    public function rank(array $findings): array
    {
        $ranked = array_map(
            fn (Finding $finding): array => ['finding' => $finding, 'risk' => $this->for($finding)],
            $findings,
        );

        usort($ranked, static function (array $a, array $b): int {
            /** @var array{finding: Finding, risk: Risk} $a */
            /** @var array{finding: Finding, risk: Risk} $b */
            return [$b['risk']->value, $a['finding']->location->relativePath, $a['finding']->location->line, $a['finding']->ruleId]
                <=> [$a['risk']->value, $b['finding']->location->relativePath, $b['finding']->location->line, $b['finding']->ruleId];
        });

        return $ranked;
    }

    /**
     * A file's risk is the RAW SUM of its findings' risk, not their density.
     *
     * A file with twelve findings should be read before a file with one, even
     * if it is longer. Density is the right measure for quality and the slop
     * score already provides it; total is the right measure for attention.
     * Reporting both, and documenting which is which, is how this stops being
     * mistaken for a bug.
     *
     * @param  list<Finding>  $findings
     * @return array<string, float> Relative path => summed risk, descending.
     */
    public function byFile(array $findings): array
    {
        $totals = [];

        foreach ($findings as $finding) {
            $path = $finding->location->relativePath;
            $totals[$path] = ($totals[$path] ?? 0.0) + $this->for($finding)->value;
        }

        arsort($totals);

        return $totals;
    }

    /**
     * Blast radius as the enricher recorded it, or null if it did not.
     *
     * Absent is not zero: zero would assert that nothing in the project uses
     * the subject, which is a claim, while absent says only that reach was not
     * measured here.
     */
    private function blastRadiusOf(Finding $finding): ?int
    {
        $value = $finding->metrics['blast_radius'] ?? null;

        return is_int($value) ? $value : null;
    }
}
