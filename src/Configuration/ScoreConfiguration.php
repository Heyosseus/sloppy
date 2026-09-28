<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Configuration;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Scoring\ScoreBand;

/**
 * Tunables for the slop score, read from `sloppy.score`.
 */
final readonly class ScoreConfiguration
{
    /**
     * The most one rule can take off the score. Thirty is where eight real
     * Laravel applications spread across every band instead of the two with
     * the most long methods sitting at zero, while a codebase that is poor on
     * several fronts still reaches the bottom.
     */
    public const float RULE_CAP = 30.0;

    /**
     * @param  array<string, float>  $weights  Penalty per finding, keyed by severity value.
     * @param  array<string, int>  $bands  Minimum score for a band, keyed by band value.
     */
    public function __construct(
        private array $weights = [],
        public int $linesPerUnit = 1000,
        public float $penaltyMultiplier = 1.0,
        private array $bands = [],
        public float $ruleCap = self::RULE_CAP,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        /** @var array<string, float> $weights */
        $weights = [];

        /** @var mixed $rawWeights */
        $rawWeights = $config['weights'] ?? [];

        if (is_array($rawWeights)) {
            /** @var mixed $weight */
            foreach ($rawWeights as $severity => $weight) {
                if (is_string($severity) && (is_int($weight) || is_float($weight))) {
                    $weights[$severity] = (float) $weight;
                }
            }
        }

        /** @var array<string, int> $bands */
        $bands = [];

        /** @var mixed $rawBands */
        $rawBands = $config['bands'] ?? [];

        if (is_array($rawBands)) {
            /** @var mixed $minimum */
            foreach ($rawBands as $band => $minimum) {
                if (is_string($band) && is_int($minimum)) {
                    $bands[$band] = $minimum;
                }
            }
        }

        $linesPerUnit = $config['lines_per_unit'] ?? 1000;
        $multiplier = $config['penalty_multiplier'] ?? 1.0;
        $cap = $config['rule_cap'] ?? self::RULE_CAP;

        return new self(
            weights: $weights,
            linesPerUnit: is_int($linesPerUnit) && $linesPerUnit > 0 ? $linesPerUnit : 1000,
            penaltyMultiplier: is_int($multiplier) || is_float($multiplier) ? (float) $multiplier : 1.0,
            bands: $bands,
            ruleCap: (is_int($cap) || is_float($cap)) && $cap >= 0 ? (float) $cap : self::RULE_CAP,
        );
    }

    /**
     * Penalty weight for a severity, falling back to the enum's own default.
     */
    public function weightFor(Severity $severity): float
    {
        return $this->weights[$severity->value] ?? $severity->defaultWeight();
    }

    /**
     * Translate a numeric score into its band, walking from best to worst.
     */
    public function bandFor(int $score): ScoreBand
    {
        $defaults = [
            ScoreBand::Clean->value => 90,
            ScoreBand::Healthy->value => 75,
            ScoreBand::NeedsAttention->value => 60,
            ScoreBand::Sloppy->value => 40,
        ];

        foreach ($defaults as $band => $default) {
            if ($score >= ($this->bands[$band] ?? $default)) {
                return ScoreBand::from($band);
            }
        }

        return ScoreBand::Severe;
    }
}
