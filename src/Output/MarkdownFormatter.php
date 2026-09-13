<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Contracts\Formatter;
use Heyosseus\Sloppy\Scoring\Risk;
use Heyosseus\Sloppy\Scoring\RiskCalculator;

/**
 * A pull-request comment body.
 *
 * Ordered by risk rather than by file, because a reviewer with ten minutes
 * needs the order and not the inventory. Everything below the first few
 * findings goes inside a collapsed block, so the comment is short enough to
 * read in the timeline and complete enough to be the only comment needed.
 */
final readonly class MarkdownFormatter implements Formatter
{
    /**
     * How many findings stay above the fold.
     *
     * Five is about what fits in a GitHub comment before it starts scrolling,
     * and a reviewer who reads five ranked findings has spent their attention
     * on the five that earned it.
     */
    private const int VISIBLE = 5;

    public function __construct(
        private RiskCalculator $risk = new RiskCalculator,
        private bool $explainRisk = false,
    ) {}

    public function format(AnalysisResult $result): string
    {
        $lines = [
            sprintf('## Sloppy — %d/100 %s', $result->score->value, $result->score->label()),
            '',
            sprintf(
                '%d finding(s) across %d file(s) · %s lines analysed',
                $result->count(),
                $result->fileCount(),
                number_format($result->analyzedLines),
            ),
        ];

        if ($result->isEmpty()) {
            $lines[] = '';
            $lines[] = 'Nothing to report.';

            return $this->finish($lines, $result);
        }

        $ranked = $this->risk->rank($result->findings);
        $visible = array_slice($ranked, 0, self::VISIBLE);
        $hidden = array_slice($ranked, self::VISIBLE);

        $lines[] = '';
        $lines[] = '### Read in this order';
        $lines[] = '';

        foreach ($visible as $position => $row) {
            $lines = [...$lines, ...$this->finding($position + 1, $row['finding'], $row['risk'])];
        }

        if ($hidden !== []) {
            $lines[] = '<details>';
            $lines[] = sprintf('<summary>%d more, in the same order</summary>', count($hidden));
            $lines[] = '';

            foreach ($hidden as $position => $row) {
                $lines = [...$lines, ...$this->finding($position + 1 + count($visible), $row['finding'], $row['risk'])];
            }

            $lines[] = '</details>';
            $lines[] = '';
        }

        return $this->finish($lines, $result);
    }

    /**
     * @return list<string>
     */
    private function finding(int $position, Finding $finding, Risk $risk): array
    {
        $lines = [sprintf(
            '**%d. `%s` %s** — `%s:%d` · risk %.1f · %d%% confidence',
            $position,
            $finding->ruleId,
            $finding->ruleName,
            $finding->location->relativePath,
            $finding->location->line,
            $risk->value,
            $finding->confidence,
        )];

        $lines[] = '';
        $lines[] = $finding->message;
        $lines[] = '';
        $lines[] = '> '.$finding->suggestion;

        if ($this->explainRisk) {
            $lines[] = '>';
            $lines[] = '> `'.$risk->explain().'`';
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private function finish(array $lines, AnalysisResult $result): string
    {
        if ($result->skippedRules !== []) {
            $lines[] = sprintf(
                '_%d rule(s) skipped for a missing framework: %s._',
                count($result->skippedRules),
                implode(', ', $result->skippedRules),
            );
            $lines[] = '';
        }

        if ($result->errors !== []) {
            $lines[] = sprintf('_%d file(s) or rule(s) could not be analysed._', count($result->errors));
            $lines[] = '';
        }

        $lines[] = '<sub>Risk ranks what to read: severity × confidence × reach. '
            .'It is not the slop score, which measures quality and is density-normalised.</sub>';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
