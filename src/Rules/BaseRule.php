<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules;

use Heyosseus\Sloppy\Analysis\AnalysisContext;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Location;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Contracts\Rule;
use PhpParser\Node;

/**
 * Option reading, severity overrides and finding construction, so that a rule
 * file contains only the detection it is named after.
 */
abstract class BaseRule implements Rule
{
    /**
     * @param  array<string, mixed>  $options  From `sloppy.rules.<ID>`.
     */
    public function __construct(protected array $options = []) {}

    /**
     * Severity for this rule's findings.
     *
     * Teams can downgrade a rule they disagree with rather than switching it
     * off, which keeps the finding visible without failing their build.
     */
    public function severity(): Severity
    {
        $override = $this->options['severity'] ?? null;

        if (is_string($override) && trim($override) !== '') {
            return Severity::parse($override);
        }

        return $this->defaultSeverity();
    }

    /**
     * By default a rule explains itself with its own description.
     */
    public function explanation(): string
    {
        return $this->description();
    }

    abstract protected function defaultSeverity(): Severity;

    protected function intOption(string $key, int $default): int
    {
        $value = $this->options[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) && is_string($value) ? (int) $value : $default;
    }

    protected function floatOption(string $key, float $default): float
    {
        $value = $this->options[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : $default;
    }

    protected function boolOption(string $key, bool $default): bool
    {
        $value = $this->options[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    protected function listOption(string $key, array $default): array
    {
        $value = $this->options[$key] ?? null;

        if (! is_array($value)) {
            return $default;
        }

        $items = [];

        /** @var mixed $item */
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }

    /**
     * Build a finding, filling in everything derivable from the rule itself.
     *
     * @param  array<string, string|int|float|bool>  $metrics
     */
    protected function report(
        AnalysisContext $context,
        Node|Location $at,
        string $message,
        string $suggestion,
        int $confidence,
        string $fingerprint,
        array $metrics = [],
    ): Finding {
        return new Finding(
            ruleId: $this->id(),
            ruleName: $this->name(),
            category: $this->category(),
            severity: $this->severity(),
            confidence: $confidence,
            location: $at instanceof Location ? $at : $context->locate($at),
            message: $message,
            explanation: $this->explanation(),
            suggestion: $suggestion,
            fingerprint: $fingerprint,
            metrics: $metrics,
        );
    }

    /**
     * Nudge a confidence upward as evidence accumulates, without ever claiming
     * certainty a heuristic cannot support.
     *
     * @param  list<bool>  $signals
     */
    protected function confidenceFrom(int $base, array $signals, int $perSignal = 8, int $ceiling = 95): int
    {
        $confidence = $base;

        foreach ($signals as $signal) {
            if ($signal) {
                $confidence += $perSignal;
            }
        }

        return min($ceiling, $confidence);
    }
}
