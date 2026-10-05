<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Sloppy;

/**
 * Which report a format means for a diff.
 *
 * `sloppy diff`, `sloppy review` and `sloppy ci` all render a comparison, and
 * every format they accept renders it here, so none of them can quietly fall
 * back to the console report for a format it was never taught.
 *
 * The formats with a diff-aware shape get the diff. The ones whose shape is a
 * run -- SARIF, Code Quality, a Rector config -- get the change's new findings,
 * or with `$wholeFiles` everything the changed files carry now: GitLab works
 * out which findings are new itself, by comparing against the target branch's
 * report, and handing it only the new ones would break that comparison.
 */
final readonly class DiffFormatterFactory
{
    public function __construct(
        private Sloppy $sloppy,
        private bool $explain = false,
        private bool $explainRisk = false,
        private ?Severity $failOn = null,
        private bool $review = false,
        private bool $wholeFiles = false,
    ) {}

    public function format(DiffReport $report, OutputFormat $format): string
    {
        $scan = new FormatterFactory(sloppy: $this->sloppy, explainRisk: $this->explainRisk);

        return match ($format) {
            OutputFormat::Json => (new DiffJsonFormatter(
                explainRisk: $this->explainRisk,
                risk: $this->sloppy->risks(),
                tiers: $scan->tiers(),
            ))->format($report),
            OutputFormat::Github => (new DiffGithubFormatter(new GithubFormatter($scan->pathPrefix())))->format($report),
            OutputFormat::Markdown => $this->review()->format($report),
            // Risk arithmetic is something the reading-order report shows, so
            // asking for it is asking for that report.
            OutputFormat::Console => $this->review || $this->explainRisk
                ? $this->review(console: true)->format($report)
                : (new DiffConsoleFormatter(explain: $this->explain, failOn: $this->failOn))->format($report),
            OutputFormat::Sarif, OutputFormat::Gitlab, OutputFormat::Rector => $scan
                ->for($format)
                ->format($this->wholeFiles ? $report->currentResult() : $report->newResult()),
        };
    }

    private function review(bool $console = false): ReviewFormatter
    {
        return new ReviewFormatter(
            risk: $this->sloppy->risks(),
            explainRisk: $this->explainRisk,
            markdown: ! $console,
        );
    }
}
