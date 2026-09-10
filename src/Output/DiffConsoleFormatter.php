<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Contracts\DiffFormatter;
use Heyosseus\Sloppy\Git\DiffReport;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The review report: what this change introduced, and what it cleaned up.
 *
 * New findings get the detail, because they are the ones someone can still
 * decide not to merge. Existing findings are counted but not listed -- they are
 * not this change's fault and drowning the review in them is how people learn
 * to ignore the tool.
 */
final readonly class DiffConsoleFormatter implements DiffFormatter
{
    public function __construct(
        private bool $explain = false,
        private ?Severity $failOn = null,
    ) {}

    public function format(DiffReport $report): string
    {
        $lines = ['', '  <options=bold>Sloppy Review</>', ''];
        $lines[] = sprintf('  Base   <fg=cyan>%s</>', OutputFormatter::escape($report->base));
        $lines[] = sprintf(
            '  Files  %d changed  ·  %d lines touched',
            $report->changedFileCount(),
            $report->changedLineCount(),
        );

        if ($report->changedFiles === []) {
            $lines[] = '';
            $lines[] = '  <fg=gray>No analysable PHP changes against '.OutputFormatter::escape($report->base).'.</>';
            $lines[] = '';

            return implode("\n", $lines)."\n";
        }

        $lines[] = '';
        $lines[] = count($report->new) === 0
            ? '  <fg=green;options=bold>No new findings.</>'
            : sprintf('  <options=bold>New findings: %d</>', count($report->new));

        foreach ($report->new as $finding) {
            $lines = [...$lines, ...$this->renderFinding($finding)];
        }

        if ($report->resolved !== []) {
            $lines[] = '';
            $lines[] = sprintf('  <fg=green;options=bold>Resolved: %d</>', count($report->resolved));

            foreach ($report->resolved as $finding) {
                $lines[] = sprintf(
                    '         <fg=green>%s</>  %s  <fg=gray>%s</>',
                    $finding->ruleId,
                    $finding->ruleName,
                    OutputFormatter::escape($finding->location->relativePath),
                );
            }
        }

        $lines[] = '';
        $lines[] = '  <fg=gray>'.str_repeat('─', 60).'</>';
        $lines[] = '';
        $lines[] = sprintf(
            '  New <options=bold>%d</>   Existing <fg=gray>%d</>   Resolved <fg=green>%d</>',
            count($report->new),
            count($report->existing),
            count($report->resolved),
        );
        $lines[] = '';
        $lines[] = $this->scoreLine($report);

        if ($this->failOn instanceof Severity) {
            $breaching = count($report->newAtOrAbove($this->failOn));

            $lines[] = '';
            $lines[] = '  '.($breaching > 0
                ? sprintf('<fg=red;options=bold>✗ Failed</> — %d new finding(s) at %s or above', $breaching, $this->failOn->value)
                : sprintf('<fg=green;options=bold>✓ Passed</> — no new findings at %s or above', $this->failOn->value));
        }

        if ($report->errors !== []) {
            $lines[] = '';
            $lines[] = '  <fg=yellow>Could not be analysed:</>';

            foreach ($report->errors as $where => $message) {
                $lines[] = '    <fg=yellow>'.OutputFormatter::escape($where).'</> — '.OutputFormatter::escape($message);
            }
        }

        $lines[] = '';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function renderFinding(Finding $finding): array
    {
        $lines = [
            '',
            sprintf(
                '  <fg=%s;options=bold>%-8s</> <options=bold>%s</>  %s  <fg=gray>(%d%%)</>',
                $finding->severity->color(),
                $finding->severity->label(),
                $finding->ruleId,
                $finding->ruleName,
                $finding->confidence,
            ),
            '         <fg=white>'.OutputFormatter::escape((string) $finding->location).'</>',
            '         '.OutputFormatter::escape($finding->message),
        ];

        if ($this->explain) {
            foreach (explode("\n", wordwrap($finding->explanation, 84)) as $line) {
                $lines[] = '         <fg=gray>'.OutputFormatter::escape($line).'</>';
            }
        }

        foreach (explode("\n", wordwrap($finding->suggestion, 84)) as $index => $line) {
            $lines[] = ($index === 0 ? '         <fg=cyan>→ </>' : '           ').OutputFormatter::escape($line);
        }

        return $lines;
    }

    private function scoreLine(DiffReport $report): string
    {
        $delta = $report->scoreDelta();

        $movement = match (true) {
            $delta > 0 => sprintf('<fg=green>+%d</>', $delta),
            $delta < 0 => sprintf('<fg=red>%d</>', $delta),
            default => '<fg=gray>no change</>',
        };

        return sprintf(
            '  Score  <fg=%s;options=bold>%d/100</> <fg=%s>%s</>  <fg=gray>(was %d/100)</>  %s',
            $report->currentScore->band->color(),
            $report->currentScore->value,
            $report->currentScore->band->color(),
            $report->currentScore->label(),
            $report->baseScore->value,
            $movement,
        );
    }
}
