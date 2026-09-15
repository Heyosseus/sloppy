<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Configuration;

use Heyosseus\Sloppy\Analysis\Severity;

/**
 * Every number the risk model uses, in one place with its reason.
 *
 * These are weights, not measurements, and the package has already been told
 * that unexplained constants are the thing people distrust most. So each one
 * is defended here, every one is configurable, and `--explain-risk` prints the
 * arithmetic they produce for any finding on demand. A number you can watch a
 * tool derive is not a magic number.
 */
final readonly class RiskConfiguration
{
    /**
     * A finding the current change introduced is the one worth reading now.
     * Inherited findings are what a baseline is for -- discounted rather than
     * zeroed, so "you touched a file that was already bad" stays visible
     * without being able to dominate the ranking.
     */
    public const float NOVELTY_NEW = 1.0;

    public const float NOVELTY_INHERITED = 0.25;

    /**
     * Inside a hunk the change actually touched, against elsewhere in a file
     * it touched. A god method the change created and a god method it merely
     * stood next to are not the same finding, and nothing else can tell them
     * apart because nothing else has the hunks in hand at rule time.
     */
    public const float PROXIMITY_IN_HUNK = 1.0;

    public const float PROXIMITY_NEARBY = 0.3;

    /**
     * How much an untested file outranks a fully tested one.
     *
     * At 0.5 an untested file ranks 1.5x an identical covered one: enough to
     * lift it past a slightly worse finding in well-tested code, not enough to
     * let coverage dominate severity. Coverage is a statement about what a
     * mistake here would cost to discover, never about whether this is a
     * mistake -- which is also why it moves the reading order and never the
     * score.
     */
    public const float EXPOSURE_WEIGHT = 0.5;

    /**
     * @param  array<string, float>  $severityWeights  Severity value => weight.
     * @param  float  $reachWeight  Multiplier on the logarithm of the blast radius.
     */
    public function __construct(
        private array $severityWeights = [
            'critical' => 20.0,
            'high' => 10.0,
            'medium' => 4.0,
            'low' => 1.5,
            'info' => 0.5,
        ],
        private float $reachWeight = 1.0,
        private float $exposureWeight = self::EXPOSURE_WEIGHT,
        private ?string $coveragePath = null,
    ) {}

    /**
     * An explicit coverage report path, or null to autodetect.
     *
     * It lives in the risk block rather than at the top level because that is
     * the only thing it feeds. Coverage changes the order findings are read in
     * and never the score, and a configuration key that says so is one fewer
     * thing to explain.
     */
    public function coveragePath(): ?string
    {
        return $this->coveragePath;
    }

    /**
     * @param  array<string, mixed>  $config  The `sloppy.risk` array.
     */
    public static function fromArray(array $config): self
    {
        $weights = [];

        $rawWeights = $config['severity_weights'] ?? null;

        if (is_array($rawWeights)) {
            /** @var mixed $weight */
            foreach ($rawWeights as $severity => $weight) {
                if (is_string($severity) && (is_int($weight) || is_float($weight))) {
                    $weights[$severity] = (float) $weight;
                }
            }
        }

        $reach = $config['reach_weight'] ?? null;
        $exposure = $config['exposure_weight'] ?? null;
        $coverage = $config['coverage'] ?? null;

        $defaults = new self;

        return new self(
            severityWeights: $weights === [] ? $defaults->severityWeights : $weights,
            reachWeight: is_int($reach) || is_float($reach) ? max(0.0, (float) $reach) : $defaults->reachWeight,
            exposureWeight: is_int($exposure) || is_float($exposure)
                ? max(0.0, (float) $exposure)
                : $defaults->exposureWeight,
            coveragePath: is_string($coverage) && trim($coverage) !== '' ? trim($coverage) : null,
        );
    }

    public function weightFor(Severity $severity): float
    {
        return $this->severityWeights[$severity->value] ?? 1.0;
    }

    /**
     * Reach grows with the logarithm of the blast radius, not with the radius.
     *
     * A class with two hundred callers is not two hundred times more urgent
     * than one with a single caller; the tenth caller costs less new attention
     * than the first. At the default weight one usage yields 1.30, ten yields
     * 2.04 and a hundred yields 3.00 -- a 2.3x spread across two orders of
     * magnitude, which is roughly the spread a reviewer actually feels.
     *
     * A finding with no measured blast radius gets 1.0: unknown reach must
     * neither promote nor demote it.
     */
    public function reachFor(?int $blastRadius): float
    {
        if ($blastRadius === null || $blastRadius < 0) {
            return 1.0;
        }

        return 1.0 + log10(1 + $blastRadius) * $this->reachWeight;
    }

    public function reachWeight(): float
    {
        return $this->reachWeight;
    }

    /**
     * A file the tests never execute is a file where a mistake survives to
     * production, so it earns a closer read.
     *
     * Unknown coverage returns 1.0. The factor must neither promote nor demote
     * a finding nobody has measured, which is what lets this whole feature be
     * absent without reshuffling anyone's existing ranking.
     */
    public function exposureFor(?float $coverage): float
    {
        if ($coverage === null) {
            return 1.0;
        }

        return 1.0 + (1.0 - max(0.0, min(1.0, $coverage))) * $this->exposureWeight;
    }
}
