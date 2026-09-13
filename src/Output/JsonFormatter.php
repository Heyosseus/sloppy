<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Contracts\Formatter;
use Heyosseus\Sloppy\Scoring\RiskCalculator;

/**
 * The machine-facing report.
 *
 * The shape is a published contract: `schema` is bumped when a field changes
 * meaning, and keys are only ever added within a schema version. Findings
 * appear in the analyser's canonical order, so the output of two runs over the
 * same code is byte-identical and safe to diff in CI.
 */
final readonly class JsonFormatter implements Formatter
{
    public const int SCHEMA = 1;

    public function __construct(
        private bool $pretty = true,
        private bool $explainRisk = false,
        private RiskCalculator $risk = new RiskCalculator,
    ) {}

    public function format(AnalysisResult $result): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

        if ($this->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $encoded = json_encode([
            'schema' => self::SCHEMA,
            'tool' => 'sloppy',
            ...$this->withRisk($result->toArray(), $result),
        ], $flags);

        return ($encoded === false ? '{}' : $encoded)."\n";
    }

    /**
     * Adds `risk` to every finding, and the factors behind it on request.
     *
     * Additive within schema 1, which the contract allows: a consumer reading
     * the documented keys is unaffected, and one that wants to rank findings
     * no longer has to reimplement the model to do it.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function withRisk(array $report, AnalysisResult $result): array
    {
        if ($result->findings === []) {
            return $report;
        }

        $byIdentity = [];

        foreach ($result->findings as $finding) {
            $byIdentity[$finding->identity()] = $finding;
        }

        /** @var list<array<string, mixed>> $findings */
        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
        $decorated = [];

        foreach ($findings as $finding) {
            $identity = $finding['identity'] ?? null;
            $source = is_string($identity) ? ($byIdentity[$identity] ?? null) : null;

            if (! $source instanceof Finding) {
                $decorated[] = $finding;

                continue;
            }

            $risk = $this->risk->for($source);
            $finding['risk'] = round($risk->value, 2);

            if ($this->explainRisk) {
                $finding['risk_factors'] = $risk->toArray();
                $finding['risk_arithmetic'] = $risk->explain();
            }

            $decorated[] = $finding;
        }

        $report['findings'] = $decorated;

        return $report;
    }
}
