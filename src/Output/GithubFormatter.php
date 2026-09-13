<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Contracts\Formatter;

/**
 * GitHub Actions workflow commands, so findings land on the diff itself.
 *
 * A report in a CI log is a report nobody reads. The same finding as a
 * `::warning file=...,line=...::` annotation appears beside the line it is
 * about, in the review the author is already looking at, which is the only
 * place a comment about code reliably changes the code.
 *
 * @see https://docs.github.com/actions/using-workflows/workflow-commands-for-github-actions
 */
final readonly class GithubFormatter implements Formatter
{
    public function format(AnalysisResult $result): string
    {
        $lines = [];

        foreach ($result->findings as $finding) {
            $lines[] = $this->annotation($finding);
        }

        if ($result->skippedRules !== []) {
            $lines[] = '::notice title=Sloppy::'.$this->escapeData(sprintf(
                '%d rule(s) skipped for a missing framework: %s.',
                count($result->skippedRules),
                implode(', ', $result->skippedRules),
            ));
        }

        foreach ($result->errors as $where => $message) {
            $lines[] = '::error title=Sloppy::'.$this->escapeData(sprintf('%s: %s', $where, $message));
        }

        // The summary goes last so it is the final thing in the log, and as a
        // notice rather than an annotation because it belongs to no line.
        $lines[] = '::notice title=Sloppy score::'.$this->escapeData(sprintf(
            '%d/100 %s -- %d finding(s) across %d file(s), %s lines analysed.',
            $result->score->value,
            $result->score->label(),
            $result->count(),
            $result->fileCount(),
            number_format($result->analyzedLines),
        ));

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function annotation(Finding $finding): string
    {
        $properties = [
            'file='.$this->escapeProperty($finding->location->relativePath),
            'line='.max(1, $finding->location->line),
        ];

        if ($finding->location->endLine !== null && $finding->location->endLine >= $finding->location->line) {
            $properties[] = 'endLine='.$finding->location->endLine;
        }

        if ($finding->location->column !== null && $finding->location->column > 0) {
            $properties[] = 'col='.$finding->location->column;
        }

        $properties[] = 'title='.$this->escapeProperty(sprintf(
            'Sloppy %s %s (%d%% confidence)',
            $finding->ruleId,
            $finding->ruleName,
            $finding->confidence,
        ));

        return sprintf(
            '::%s %s::%s',
            $this->command($finding->severity),
            implode(',', $properties),
            $this->escapeData($finding->message.' '.$finding->suggestion),
        );
    }

    /**
     * GitHub offers three annotation levels. `notice` carries the advisory
     * severities so an architecture suggestion cannot be mistaken for a defect.
     */
    private function command(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical, Severity::High => 'error',
            Severity::Medium => 'warning',
            Severity::Low, Severity::Info => 'notice',
        };
    }

    /**
     * Workflow commands are newline-delimited and `%`-escaped.
     *
     * A finding quoting a multi-line snippet would otherwise terminate its own
     * annotation early and leave the remainder to be parsed as a new command,
     * which is how tools like this emit garbage into a build log.
     */
    private function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], trim($value));
    }

    /**
     * Property values additionally escape the delimiters of the property list
     * itself.
     */
    private function escapeProperty(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            trim($value),
        );
    }
}
