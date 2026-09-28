<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Analysis\TierMap;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Configuration\RiskConfiguration;
use Heyosseus\Sloppy\Coverage\CoverageMap;
use Heyosseus\Sloppy\Git\ChurnMap;
use Heyosseus\Sloppy\Help\Surface;
use Heyosseus\Sloppy\Output\ConsoleFormatter;
use Heyosseus\Sloppy\Output\TriageFormatter;
use Heyosseus\Sloppy\Scoring\RiskCalculator;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * A result with a few defects among a lot of maintainability findings -- the
 * shape of every medium-sized application's first run.
 *
 * @param  list<Finding>  $extra
 */
function triageResult(array $extra = []): AnalysisResult
{
    $findings = [
        finding(rule: 'SL107', file: 'app/Billing.php', line: 40, fingerprint: 'b1', severity: Severity::High, name: 'Swallowed Exception', category: Category::ErrorHandling),
        finding(rule: 'SL107', file: 'app/Billing.php', line: 90, fingerprint: 'b2', severity: Severity::High, name: 'Swallowed Exception', category: Category::ErrorHandling),
        finding(rule: 'SL204', file: 'app/Report.php', line: 12, fingerprint: 'r1', severity: Severity::Medium, name: 'Query Inside Loop', category: Category::Performance),
        ...$extra,
    ];

    foreach (range(1, 30) as $i) {
        $findings[] = finding(rule: 'SL109', file: 'app/Legacy.php', line: $i, fingerprint: 'c'.$i, severity: Severity::Low, metrics: ['comment' => 'Get the user', 'kind' => 'restates'], name: 'Narrative Comment', category: Category::Readability);
    }

    $findings[] = finding(rule: 'SL101', file: 'app/Legacy.php', line: 100, fingerprint: 'm1', name: 'God Method');
    $findings[] = finding(rule: 'SL101', file: 'app/Small.php', line: 5, fingerprint: 'm2', name: 'God Method');
    $findings[] = finding(rule: 'SL303', file: 'app/Contracts/Thing.php', line: 3, fingerprint: 'a1', severity: Severity::Info, name: 'Single-Use Abstraction', category: Category::Architecture);

    return AnalysisResult::create($findings, ['app/Billing.php', 'app/Legacy.php'], 4000, new ScoreCalculator);
}

function plainTriage(TriageFormatter $formatter, AnalysisResult $result): string
{
    return (new OutputFormatter(false))->format($formatter->format($result)) ?? '';
}

it('lists the defects first, one entry per file and rule, with the other lines named', function (): void {
    $output = plainTriage(new TriageFormatter(top: 5), triageResult());

    expect($output)->toContain('Fix first  3 defects in 2 places, highest risk first')
        ->toContain('app/Billing.php:40  and 1 more in this file (line 90)')
        ->toContain('app/Report.php:12')
        ->and(substr_count($output, 'HIGH     SL107  Swallowed Exception'))->toBe(1)
        // High before medium: the swallowed exceptions carry more risk.
        ->and(strpos($output, 'app/Billing.php:40'))->toBeLessThan(strpos($output, 'app/Report.php:12'));
});

it('cuts the defect list at top, and says so', function (): void {
    $output = plainTriage(new TriageFormatter(top: 1), triageResult());

    expect($output)->toContain('3 defects in 2 places, highest risk first -- the first 1')
        ->toContain('app/Billing.php:40')
        ->not->toContain('app/Report.php:12');
});

it('gives the advice for a rule once, however many places it applies to', function (): void {
    $result = triageResult([
        finding(rule: 'SL107', file: 'app/Other.php', line: 7, fingerprint: 'o1', severity: Severity::High, name: 'Swallowed Exception', category: Category::ErrorHandling),
    ]);

    $output = plainTriage(new TriageFormatter(top: 5), $result);

    expect(substr_count($output, 'HIGH     SL107  Swallowed Exception'))->toBe(2)
        ->and(substr_count($output, 'A suggestion.'))->toBe(2);
});

it('summarises maintainability findings per file instead of listing them', function (): void {
    $output = plainTriage(new TriageFormatter(top: 5), triageResult());

    expect($output)->toContain('Hotspots  32 maintainability findings in 2 files, summarised -- the 2 most worth a look')
        ->toContain('30 × Narrative Comment · 1 × God Method')
        ->toContain('1 × God Method')
        ->not->toContain('Get the user')
        // The file with the most at stake comes first.
        ->and(strpos($output, 'app/Legacy.php'))->toBeLessThan(strpos($output, 'app/Small.php'));
});

it('names how often a hotspot changed when git history was read', function (): void {
    $risk = new RiskCalculator(new RiskConfiguration, new CoverageMap, new ChurnMap(['app/Legacy.php' => 12, 'app/Small.php' => 1]));

    $output = plainTriage(new TriageFormatter(risk: $risk, top: 5), triageResult());

    expect($output)->toContain('app/Legacy.php  12 changes in recent history')
        ->toContain('app/Small.php  1 change in recent history');
});

it('says how many hotspot files it left out', function (): void {
    $extra = array_map(
        static fn (int $i): Finding => finding(rule: 'SL101', file: sprintf('app/F%02d.php', $i), line: 1, fingerprint: 'f'.$i),
        range(1, 10),
    );

    $output = plainTriage(new TriageFormatter(top: 5), triageResult($extra));

    expect($output)->toContain('the 10 most worth a look')
        ->toContain('and 2 more files.');
});

it('counts every rule, most findings first, with its tier', function (): void {
    $output = plainTriage(new TriageFormatter(top: 5), triageResult());

    expect($output)->toMatch('/SL109\s+Narrative Comment\s+30\s+maintainability/')
        ->toMatch('/SL107\s+Swallowed Exception\s+2\s+defect/')
        ->toMatch('/SL303\s+Single-Use Abstraction\s+1\s+advisory/')
        ->and(strpos($output, 'SL109  Narrative'))->toBeLessThan(strpos($output, 'SL101  God Method'));
});

it('points at the automated fixes, the baseline and the full list, in the surface the reader used', function (): void {
    $standalone = plainTriage(new TriageFormatter(top: 5), triageResult());
    $artisan = plainTriage(new TriageFormatter(surface: Surface::Artisan, top: 5), triageResult());

    // Thirty restating comments are removed by the fix command itself, and
    // SL107 is one Rector knows about.
    expect($standalone)->toContain('32 findings have an automated fix: vendor/bin/sloppy fix')
        ->toContain('No baseline yet. vendor/bin/sloppy baseline accepts these 36')
        ->toContain('vendor/bin/sloppy scan --all lists every finding; add --rule=SL109 for one rule.')
        ->and($artisan)->toContain('php artisan sloppy:fix')
        ->toContain('php artisan sloppy:baseline')
        ->toContain('php artisan sloppy --all');
});

it('leaves out the baseline hint when there is one, and the fix hint when nothing is fixable', function (): void {
    $findings = array_map(
        static fn (int $i): Finding => finding(rule: 'SL101', file: 'app/A.php', line: $i, fingerprint: 'm'.$i),
        range(1, 3),
    );

    $output = plainTriage(
        new TriageFormatter(top: 1, hasBaseline: true),
        AnalysisResult::create($findings, ['app/A.php'], 1000, new ScoreCalculator),
    );

    expect($output)->not->toContain('No baseline yet')
        ->not->toContain('automated fix')
        ->toContain('Fix first')
        ->toContain('No defects: nothing here is an error waiting to happen.');
});

it('spells out five of a group\'s other lines, then trails off', function (): void {
    $findings = array_map(
        static fn (int $i): Finding => finding(rule: 'SL107', file: 'app/A.php', line: $i * 10, fingerprint: 's'.$i, name: 'Swallowed Exception', category: Category::ErrorHandling),
        range(1, 8),
    );

    $output = plainTriage(new TriageFormatter(top: 3), AnalysisResult::create($findings, ['app/A.php'], 1000, new ScoreCalculator));

    expect($output)->toContain('and 7 more in this file (lines 20, 30, 40, 50, 60, …)')
        ->not->toContain('Hotspots');
});

it('shows the full report when the run is short enough to read', function (): void {
    $result = AnalysisResult::create([finding()], ['app/Order.php'], 1000, new ScoreCalculator);

    expect((new TriageFormatter)->format($result))->toBe((new ConsoleFormatter)->format($result));
});

it('keeps the score, the summary and the verdict', function (): void {
    $output = plainTriage(
        new TriageFormatter(console: new ConsoleFormatter(failOn: Severity::High), top: 5),
        triageResult(),
    );

    expect($output)->toContain('Score')
        ->toContain('36 findings')
        ->toContain('Failed');
});

it('files a rule where its configuration says', function (): void {
    $tiers = new TierMap(Configuration::fromArray(['rules' => ['SL109' => ['tier' => 'defect']]], '/tmp'));

    $output = plainTriage(new TriageFormatter(tiers: $tiers, top: 50), triageResult(array_map(
        static fn (int $i): Finding => finding(rule: 'SL101', file: 'app/B.php', line: $i, fingerprint: 'x'.$i),
        range(1, 20),
    )));

    expect($output)->toContain('33 defects in 3 places')
        ->toMatch('/SL109\s+Narrative Comment\s+30\s+defect/');
});

it('wraps a long tally between entries, never through one', function (): void {
    $names = ['Unused Constructor Dependency', 'Defensive Programming Noise', 'Excessive Nesting', 'Business Logic In Controller', 'Copy-Paste Drift'];
    $extra = array_map(
        static fn (int $i): Finding => finding(rule: 'SL1'.(20 + $i), file: 'app/Wide.php', line: $i, fingerprint: 'w'.$i, name: $names[$i]),
        array_keys($names),
    );

    $output = plainTriage(new TriageFormatter(top: 5), triageResult($extra));

    expect($output)->toContain(
        "         1 × Business Logic In Controller · 1 × Copy-Paste Drift\n"
        ."         1 × Defensive Programming Noise · 1 × Excessive Nesting\n"
        ."         1 × Unused Constructor Dependency\n",
    );
});
