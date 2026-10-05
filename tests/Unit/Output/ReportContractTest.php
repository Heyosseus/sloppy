<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Output\DiffGithubFormatter;
use Heyosseus\Sloppy\Output\DiffJsonFormatter;
use Heyosseus\Sloppy\Output\GithubFormatter;
use Heyosseus\Sloppy\Output\GitlabFormatter;
use Heyosseus\Sloppy\Output\JsonEncoder;
use Heyosseus\Sloppy\Output\JsonFormatter;
use Heyosseus\Sloppy\Output\SarifFormatter;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

/**
 * The machine-readable reports: what a consumer can rely on whatever the
 * analysed code contained.
 */
function contractReport(array $new = [], array $errors = []): DiffReport
{
    $score = (new ScoreCalculator)->calculate($new, 100);

    return new DiffReport(
        base: 'HEAD',
        changedFiles: [new ChangedFile('app/Order.php', 'modified')],
        new: $new,
        existing: [],
        resolved: [],
        currentScore: $score,
        baseScore: $score,
        errors: $errors,
    );
}

function latin1Finding(): Heyosseus\Sloppy\Analysis\Finding
{
    // A message quoting a Latin-1 literal from the analysed source is not
    // valid UTF-8, and used to turn the whole report into `{}`.
    return finding(fingerprint: "Order::caf\xE9", name: "God \xE9 Method");
}

it('substitutes invalid UTF-8 rather than emitting an empty report', function (): void {
    $result = analysisResult([latin1Finding()]);

    $json = (new JsonFormatter)->format($result);
    $diff = (new DiffJsonFormatter)->format(contractReport([latin1Finding()]));
    $gitlab = (new GitlabFormatter)->format($result);
    $sarif = (new SarifFormatter)->format($result);

    foreach ([$json, $diff, $gitlab, $sarif] as $report) {
        expect(json_decode($report, true, 512, JSON_THROW_ON_ERROR))->toBeArray()->not->toBeEmpty()
            ->and($report)->toContain("God \u{FFFD} Method");
    }
});

it('raises an encoding failure instead of writing nothing', function (): void {
    expect(fn (): string => (new JsonEncoder)->encode(['score' => NAN]))
        ->toThrow(RuntimeException::class, 'The report could not be encoded as JSON');
});

it('always encodes errors as an object', function (): void {
    /** @var array{errors: mixed} $clean */
    $clean = json_decode((new JsonFormatter)->format(analysisResult()), false, 512, JSON_THROW_ON_ERROR);
    /** @var array{errors: mixed} $broken */
    $broken = json_decode((new JsonFormatter)->format(new AnalysisResult(
        findings: [],
        analyzedFiles: [],
        analyzedLines: 0,
        score: (new ScoreCalculator)->calculate([], 0),
        errors: ['app/Bad.php' => 'Syntax error'],
    )), false, 512, JSON_THROW_ON_ERROR);
    $diff = json_decode((new DiffJsonFormatter)->format(contractReport()), false, 512, JSON_THROW_ON_ERROR);

    expect($clean->errors)->toBeInstanceOf(stdClass::class)
        ->and($broken->errors)->toBeInstanceOf(stdClass::class)
        ->and($diff->errors)->toBeInstanceOf(stdClass::class)
        ->and((new JsonFormatter)->format(analysisResult()))->toContain('"errors": {}');
});

it('gives diff findings the risk and tier a scan gives them', function (): void {
    /** @var array{new: list<array<string, mixed>>, summary: array<string, int>} $decoded */
    $decoded = json_decode((new DiffJsonFormatter(explainRisk: true))->format(contractReport([finding()])), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['new'][0])->toHaveKeys(['risk', 'tier', 'risk_factors', 'risk_arithmetic'])
        ->and(array_keys($decoded['summary']))->toBe(['new', 'existing', 'resolved']);
});

it('names files from the repository root in annotations, SARIF and Code Quality', function (): void {
    $result = analysisResult([finding(file: 'app/My Order.php')]);

    $sarif = json_decode((new SarifFormatter('dev', 'packages/shop/'))->format($result), true, 512, JSON_THROW_ON_ERROR);
    $gitlab = json_decode((new GitlabFormatter(pathPrefix: 'packages/shop/'))->format($result), true, 512, JSON_THROW_ON_ERROR);

    expect((new GithubFormatter('packages/shop/'))->format($result))->toContain('file=packages/shop/app/My Order.php,')
        ->and($sarif['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'])
        ->toBe('packages/shop/app/My%20Order.php')
        ->and($gitlab[0]['location']['path'])->toBe('packages/shop/app/My Order.php')
        ->and((new DiffGithubFormatter(new GithubFormatter('pkg/')))->format(contractReport([finding()])))
        ->toContain('file=pkg/app/Order.php');
});

it('percent-encodes a SARIF uri without a prefix too', function (): void {
    $sarif = json_decode((new SarifFormatter)->format(analysisResult([finding(file: 'app/Café #1.php')])), true, 512, JSON_THROW_ON_ERROR);

    expect($sarif['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'])
        ->toBe('app/Caf%C3%A9%20%231.php');
});

it('gives findings that share an identity distinct, stable fingerprints', function (): void {
    $twins = analysisResult([finding(line: 10), finding(line: 20), finding(fingerprint: 'Other::thing')]);

    $gitlab = array_column(json_decode((new GitlabFormatter)->format($twins), true, 512, JSON_THROW_ON_ERROR), 'fingerprint');
    $sarif = array_map(
        static fn (array $result): string => $result['partialFingerprints']['sloppyIdentity/v1'],
        json_decode((new SarifFormatter)->format($twins), true, 512, JSON_THROW_ON_ERROR)['runs'][0]['results'],
    );
    $again = array_column(json_decode((new GitlabFormatter)->format($twins), true, 512, JSON_THROW_ON_ERROR), 'fingerprint');

    expect(array_unique($gitlab))->toHaveCount(3)
        ->and($gitlab[0])->toBe(finding()->identity())
        ->and($sarif)->toBe($gitlab)
        ->and($again)->toBe($gitlab);
});
