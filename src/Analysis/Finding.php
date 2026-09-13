<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

/**
 * One reported pattern.
 *
 * A finding says four things: what was detected (`message`), why that may be a
 * problem (`explanation`), what could be done about it (`suggestion`), and how
 * sure we are the pattern is really there (`confidence`).
 *
 * Confidence is NOT a probability that the code was written by an AI. Sloppy
 * makes no claim about authorship. It is only the analyser's certainty that the
 * pattern it describes is present in the code.
 */
final readonly class Finding
{
    /**
     * 0-100 certainty that the described pattern is actually present.
     */
    public int $confidence;

    /**
     * @param  array<string, string|int|float|bool>  $metrics  Measurements behind the finding, surfaced in JSON output.
     */
    public function __construct(
        public string $ruleId,
        public string $ruleName,
        public Category $category,
        public Severity $severity,
        int $confidence,
        public Location $location,
        public string $message,
        public string $explanation,
        public string $suggestion,
        public string $fingerprint,
        public array $metrics = [],
    ) {
        $this->confidence = max(0, min(100, $confidence));
    }

    /**
     * Stable identity used by baselines and diff comparison.
     *
     * Deliberately excludes the line number: adding an import at the top of a
     * file must not turn every existing finding in it into a "new" one. The
     * fingerprint supplied by the rule (usually a class and member name) is
     * what makes two findings the same finding.
     */
    public function identity(): string
    {
        return substr(hash('sha256', implode("\0", [
            $this->ruleId,
            $this->location->relativePath,
            $this->fingerprint,
        ])), 0, 16);
    }

    /**
     * The same finding with extra measurements attached.
     *
     * Used by post-analysis passes that know something a rule cannot: reach
     * across the project, proximity to a change. Existing keys win, so a rule
     * that measured something itself is never overwritten by a pass guessing
     * at the same thing.
     *
     * @param  array<string, string|int|float|bool>  $extra
     */
    public function withMetrics(array $extra): self
    {
        return new self(
            ruleId: $this->ruleId,
            ruleName: $this->ruleName,
            category: $this->category,
            severity: $this->severity,
            confidence: $this->confidence,
            location: $this->location,
            message: $this->message,
            explanation: $this->explanation,
            suggestion: $this->suggestion,
            fingerprint: $this->fingerprint,
            metrics: $this->metrics + $extra,
        );
    }

    /**
     * Penalty this finding contributes before codebase-size normalisation.
     *
     * A finding we are half sure about counts half as much.
     */
    public function weightedPenalty(float $severityWeight): float
    {
        return $severityWeight * ($this->confidence / 100);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->ruleId,
            'name' => $this->ruleName,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'confidence' => $this->confidence,
            'file' => $this->location->relativePath,
            'line' => $this->location->line,
            'end_line' => $this->location->endLine,
            'column' => $this->location->column,
            'message' => $this->message,
            'explanation' => $this->explanation,
            'suggestion' => $this->suggestion,
            'fingerprint' => $this->fingerprint,
            'identity' => $this->identity(),
            'metrics' => $this->metrics,
        ];
    }
}
