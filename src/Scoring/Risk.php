<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Scoring;

/**
 * One finding's risk, and every factor that produced it.
 *
 * The factors are kept rather than folded away so the number can show its own
 * work. `--explain-risk` prints this; nothing has to recompute it to explain
 * it, which is the only way an explanation cannot drift from the value.
 */
final readonly class Risk
{
    public function __construct(
        public float $value,
        public float $severityWeight,
        public string $severityLabel,
        public float $confidence,
        public float $novelty,
        public string $noveltyLabel,
        public float $proximity,
        public string $proximityLabel,
        public float $reach,
        public ?int $blastRadius,
        public float $exposure = 1.0,
        public string $exposureLabel = 'coverage unknown',
    ) {}

    /**
     * The arithmetic, as one line, in the order the formula multiplies it.
     *
     * Reads as:
     *   10.0 (high) x 0.88 (confidence) x 1.0 (new) x 1.0 (in hunk) x 2.04 (47 usages) x 1.50 (untested) = 26.93
     */
    public function explain(): string
    {
        return sprintf(
            '%.1f (%s) x %.2f (confidence) x %.2f (%s) x %.2f (%s) x %.2f (%s) x %.2f (%s) = %.2f',
            $this->severityWeight,
            $this->severityLabel,
            $this->confidence,
            $this->novelty,
            $this->noveltyLabel,
            $this->proximity,
            $this->proximityLabel,
            $this->reach,
            $this->blastRadius === null
                ? 'reach unmeasured'
                : sprintf('%d usage%s', $this->blastRadius, $this->blastRadius === 1 ? '' : 's'),
            $this->exposure,
            $this->exposureLabel,
            $this->value,
        );
    }

    /**
     * @return array<string, string|int|float|null>
     */
    public function toArray(): array
    {
        return [
            'value' => round($this->value, 2),
            'severity_weight' => $this->severityWeight,
            'confidence' => round($this->confidence, 2),
            'novelty' => $this->novelty,
            'novelty_label' => $this->noveltyLabel,
            'proximity' => $this->proximity,
            'proximity_label' => $this->proximityLabel,
            'reach' => round($this->reach, 2),
            'blast_radius' => $this->blastRadius,
            'exposure' => round($this->exposure, 2),
            'exposure_label' => $this->exposureLabel,
        ];
    }
}
