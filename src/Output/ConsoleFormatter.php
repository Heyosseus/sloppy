<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Contracts\Formatter;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The human-facing report.
 *
 * Findings are grouped by file, because that is how they get fixed. Each one
 * says what was found and what to do about it; the longer "why this matters"
 * text is available with --explain rather than repeated dozens of times.
 *
 * Anything quoted from the analysed code is escaped: a message containing
 * `<p>` or `Order|Invoice` is source text, not console markup.
 */
final readonly class ConsoleFormatter implements Formatter
{
    public function __construct(
        private bool $explain = false,
        private ?Severity $failOn = null,
    ) {}

    public function format(AnalysisResult $result): string
    {
        $lines = ['', '  <options=bold>Sloppy</>', ''];

        $score = $result->score;
        $lines[] = sprintf(
            '  Score  <fg=%s;options=bold>%d/100</>  <fg=%s>%s</>',
            $score->band->color(),
            $score->value,
            $score->band->color(),
            $score->label(),
        );
        $lines[] = sprintf(
            '  Files  %s analysed  ·  %s lines  ·  %s',
            number_format($result->fileCount()),
            number_format($result->analyzedLines),
            $this->countLabel($result->count()),
        );

        if ($result->isEmpty()) {
            $lines[] = '';
            $lines[] = '  <fg=green>Nothing flagged.</>';
            $lines[] = '';

            return $this->join($lines, $result);
        }

        foreach ($result->groupedByFile() as $file => $findings) {
            $lines[] = '';
            $lines[] = '  <fg=white;options=bold>'.OutputFormatter::escape($file).'</>';

            foreach ($findings as $finding) {
                $lines = [...$lines, ...$this->renderFinding($finding)];
            }
        }

        $lines[] = '';
        $lines[] = '  <fg=gray>'.str_repeat('─', 60).'</>';
        $lines[] = '';
        $lines[] = '  '.$this->summaryLine($result);

        return $this->join($lines, $result);
    }

    /**
     * @return list<string>
     */
    private function renderFinding(Finding $finding): array
    {
        $lines = [
            '',
            sprintf(
                '  %5d  <fg=%s;options=bold>%-8s</> <options=bold>%s</>  %s  <fg=gray>(%d%% confidence)</>',
                $finding->location->line,
                $finding->severity->color(),
                $finding->severity->label(),
                $finding->ruleId,
                $finding->ruleName,
                $finding->confidence,
            ),
            '         '.OutputFormatter::escape($finding->message),
        ];

        if ($this->explain) {
            foreach ($this->wrap($finding->explanation, 84) as $line) {
                $lines[] = '         <fg=gray>'.OutputFormatter::escape($line).'</>';
            }
        }

        foreach ($this->wrap($finding->suggestion, 84) as $index => $line) {
            $lines[] = ($index === 0 ? '         <fg=cyan>→ </>' : '           ').OutputFormatter::escape($line);
        }

        return $lines;
    }

    private function summaryLine(AnalysisResult $result): string
    {
        $parts = [];

        foreach ($result->countsBySeverity() as $severity => $count) {
            if ($count > 0) {
                $parts[] = sprintf('<fg=%s>%d %s</>', Severity::from($severity)->color(), $count, $severity);
            }
        }

        $summary = $this->countLabel($result->count()).': '.implode('  ', $parts);

        if (! $this->failOn instanceof Severity) {
            return $summary;
        }

        $breaching = count($result->atOrAbove($this->failOn));

        return $summary."\n\n  ".($breaching > 0
            ? sprintf('<fg=red;options=bold>✗ Failed</> — %d finding(s) at %s or above (fail_on: %s)', $breaching, $this->failOn->value, $this->failOn->value)
            : sprintf('<fg=green;options=bold>✓ Passed</> — nothing at %s or above', $this->failOn->value));
    }

    private function countLabel(int $count): string
    {
        return $count === 1 ? '1 finding' : number_format($count).' findings';
    }

    /**
     * @param  list<string>  $lines
     */
    private function join(array $lines, AnalysisResult $result): string
    {
        if ($result->errors !== []) {
            $lines[] = '';
            $lines[] = '  <fg=yellow>Could not be analysed:</>';

            foreach ($result->errors as $where => $message) {
                $lines[] = '    <fg=yellow>'.OutputFormatter::escape($where).'</> — '.OutputFormatter::escape($message);
            }
        }

        $lines[] = '';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $width): array
    {
        return explode("\n", wordwrap($text, $width, "\n", false));
    }
}
