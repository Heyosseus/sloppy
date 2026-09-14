<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\DiffHunk;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Output\DiffConsoleFormatter;
use Heyosseus\Sloppy\Output\GithubFormatter;
use Heyosseus\Sloppy\Output\MarkdownFormatter;
use Heyosseus\Sloppy\Output\ReviewFormatter;
use Heyosseus\Sloppy\Output\SarifFormatter;
use Heyosseus\Sloppy\Scoring\Score;
use Heyosseus\Sloppy\Scoring\ScoreBand;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

/**
 * A run that could not read everything it was asked to read.
 */
function resultWithErrors(): AnalysisResult
{
    return AnalysisResult::create(
        findings: [finding()],
        analyzedFiles: ['app/Order.php'],
        analyzedLines: 200,
        calculator: new ScoreCalculator,
        errors: ['app/Broken.php' => 'Syntax error, unexpected EOF on line 12'],
    );
}

it('reports what could not be analysed, in every format that has a place for it', function (): void {
    $result = resultWithErrors();

    expect((new GithubFormatter)->format($result))->toContain('::error title=Sloppy::app/Broken.php: Syntax error')
        ->and((new MarkdownFormatter)->format($result))->toContain('_1 file(s) or rule(s) could not be analysed._')
        ->and((new SarifFormatter('1.0.0'))->format($result))->toContain('app/Broken.php: Syntax error');
});

it('reports what could not be analysed in a diff, and explains on request', function (): void {
    $report = new DiffReport(
        base: 'main',
        changedFiles: [
            new ChangedFile('app/Order.php', 'modified', [new DiffHunk(8, 6)]),
            // Not analysable: it is neither PHP nor still there.
            new ChangedFile('composer.json', 'modified', [new DiffHunk(1, 2)]),
            new ChangedFile('app/Gone.php', 'deleted'),
        ],
        new: [finding(line: 10, severity: Severity::High)],
        existing: [],
        resolved: [],
        currentScore: new Score(70, ScoreBand::NeedsAttention, 0.0, 0.0),
        baseScore: new Score(80, ScoreBand::Healthy, 0.0, 0.0),
        errors: ['app/Broken.php' => 'Syntax error, unexpected EOF on line 12'],
    );

    $console = (new DiffConsoleFormatter(explain: true, failOn: Severity::High))->format($report);

    expect($console)->toContain('Could not be analysed:')
        ->and($console)->toContain('app/Broken.php')
        ->and($console)->toContain('An explanation.')
        ->and((new ReviewFormatter)->format($report))->toContain('app/Order.php');
});
