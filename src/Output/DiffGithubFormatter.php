<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Contracts\DiffFormatter;
use Heyosseus\Sloppy\Git\DiffReport;

/**
 * Workflow annotations for a diff run.
 *
 * Only the new findings are annotated. A pull request that inherits fifty
 * findings and adds none should read as what it is -- a clean change -- and
 * fifty annotations on lines the author never touched is how a team learns to
 * collapse the annotations pane and stop reading it.
 *
 * The inherited and resolved counts still go out as a notice, so the numbers
 * are visible without being in the way.
 */
final readonly class DiffGithubFormatter implements DiffFormatter
{
    public function __construct(private GithubFormatter $annotations = new GithubFormatter) {}

    public function format(DiffReport $report): string
    {
        $delta = $report->scoreDelta();

        $summary = sprintf(
            'Against %s: %d new, %d inherited, %d resolved across %d changed file(s). Score %d/100 (%s%d).',
            $report->base,
            count($report->new),
            count($report->existing),
            count($report->resolved),
            $report->changedFileCount(),
            $report->currentScore->value,
            $delta >= 0 ? '+' : '',
            $delta,
        );

        return rtrim($this->annotations->format($report->newResult()), PHP_EOL)
            .PHP_EOL
            .'::notice title=Sloppy diff::'.str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $summary)
            .PHP_EOL;
    }
}
