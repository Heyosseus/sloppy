<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Output\MarkdownFormatter;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

/**
 * Seven findings whose FILE order deliberately disagrees with their RISK
 * order: the lowest-risk finding is first in the array and lives in
 * app/Z.php, the highest-risk finding is second and lives in app/A.php.
 *
 * @return list<Heyosseus\Sloppy\Analysis\Finding>
 */
function markdownFixtureFindings(): array
{
    return [
        finding(rule: 'SL1', file: 'app/Z.php', fingerprint: 'z1', confidence: 90, severity: Severity::Low),
        finding(rule: 'SL2', file: 'app/A.php', fingerprint: 'a1', confidence: 90, severity: Severity::Critical),
        finding(rule: 'SL3', file: 'app/B.php', fingerprint: 'b1', confidence: 90, severity: Severity::High),
        finding(rule: 'SL4', file: 'app/C.php', fingerprint: 'c1', confidence: 90, severity: Severity::Medium),
        finding(rule: 'SL5', file: 'app/D.php', fingerprint: 'd1', confidence: 90, severity: Severity::Medium),
        finding(rule: 'SL6', file: 'app/E.php', fingerprint: 'e1', confidence: 90, severity: Severity::Low),
        finding(rule: 'SL7', file: 'app/F.php', fingerprint: 'f1', confidence: 90, severity: Severity::Info),
    ];
}

it('orders findings by risk rather than by file or input order', function (): void {
    $findings = markdownFixtureFindings();
    $result = AnalysisResult::create($findings, ['app/A.php'], 100, new ScoreCalculator);

    $out = (new MarkdownFormatter)->format($result);

    // SL2 (critical, app/A.php) is the highest-risk finding despite being
    // second in the input array and alphabetically not first by file.
    expect(mb_strpos($out, '`SL2`'))->toBeLessThan((int) mb_strpos($out, '`SL1`'))
        ->and(mb_strpos($out, '`SL2`'))->toBeLessThan((int) mb_strpos($out, '`SL3`'));

    // The rendered order is a straight risk ranking, not the array order.
    $positions = [];

    foreach (['SL1', 'SL2', 'SL3', 'SL4', 'SL5', 'SL6', 'SL7'] as $rule) {
        $positions[$rule] = mb_strpos($out, '`'.$rule.'`');
    }

    asort($positions);

    expect(array_keys($positions))->toBe(['SL2', 'SL3', 'SL4', 'SL5', 'SL6', 'SL1', 'SL7']);
});

it('keeps at most five findings above the fold and moves the rest into a collapsed details block', function (): void {
    $findings = markdownFixtureFindings();
    $result = AnalysisResult::create($findings, ['app/A.php'], 100, new ScoreCalculator);

    $out = (new MarkdownFormatter)->format($result);

    $foldedPart = explode('<details>', $out, 2);

    expect($foldedPart)->toHaveCount(2);

    $above = $foldedPart[0];
    $below = $foldedPart[1];

    // Exactly five numbered entries above the fold.
    expect(preg_match_all('/^\*\*\d+\./m', $above))->toBe(5)
        ->and($below)->toContain('<summary>2 more, in the same order</summary>')
        ->and($below)->toContain('`SL1`')
        ->and($below)->toContain('`SL7`')
        ->and($above)->not->toContain('`SL1`')
        ->and($above)->not->toContain('`SL7`');
});

it('omits the details block entirely when five or fewer findings exist', function (): void {
    $findings = array_slice(markdownFixtureFindings(), 0, 3);
    $result = AnalysisResult::create($findings, ['app/A.php'], 100, new ScoreCalculator);

    $out = (new MarkdownFormatter)->format($result);

    expect($out)->not->toContain('<details>');
});

it('adds the risk arithmetic under --explain-risk and omits it otherwise', function (): void {
    $result = AnalysisResult::create([finding(confidence: 76, severity: Severity::High, metrics: ['blast_radius' => 1])], ['app/A.php'], 100, new ScoreCalculator);

    $plain = (new MarkdownFormatter(explainRisk: false))->format($result);
    $explained = (new MarkdownFormatter(explainRisk: true))->format($result);

    // A plain scan is not diff mode, so novelty and proximity are both
    // unknowable here -- this formatter's risk arithmetic reflects that.
    expect($plain)->not->toContain('confidence) x')
        ->and($explained)->toContain('10.0 (high) x 0.76 (confidence) x 1.00 (novelty unknown) x 1.00 (whole file) x 1.30 (1 usage) x 1.00 (coverage unknown) = 9.89');
});

it('reports nothing to show for an empty result without ranking anything', function (): void {
    $result = AnalysisResult::create([], ['app/A.php'], 100, new ScoreCalculator);

    $out = (new MarkdownFormatter)->format($result);

    expect($out)->toContain('Nothing to report.')
        ->not->toContain('Read in this order');
});
