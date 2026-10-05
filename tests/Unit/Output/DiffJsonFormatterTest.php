<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Git\ChangedFile;
use Heyosseus\Sloppy\Git\DiffHunk;
use Heyosseus\Sloppy\Git\DiffReport;
use Heyosseus\Sloppy\Output\DiffJsonFormatter;
use Heyosseus\Sloppy\Scoring\Score;
use Heyosseus\Sloppy\Scoring\ScoreBand;

/**
 * @param  array<string, string>  $errors
 */
function diffJsonReport(array $errors = []): DiffReport
{
    return new DiffReport(
        base: 'main',
        changedFiles: [
            new ChangedFile('app/Order.php', 'modified', [new DiffHunk(10, 4), new DiffHunk(30, 2)]),
            new ChangedFile('app/Invoice.php', 'added', [new DiffHunk(1, 20)]),
        ],
        new: [finding(rule: 'SL107', file: 'app/Order.php', line: 12, fingerprint: 'Order::pay', severity: Severity::High)],
        existing: [finding(rule: 'SL101', file: 'app/Order.php', line: 40, fingerprint: 'Order::store', severity: Severity::Medium)],
        resolved: [],
        currentScore: new Score(91, ScoreBand::Healthy, 4.25, 2.125),
        baseScore: new Score(95, ScoreBand::Clean, 1.0, 0.5),
        errors: $errors,
    );
}

/**
 * @return array<string, mixed>
 */
function decodedDiffJson(DiffJsonFormatter $formatter, DiffReport $report): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($formatter->format($report), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

it('writes the scan envelope with mode diff, a base and the changed files', function (): void {
    $data = decodedDiffJson(new DiffJsonFormatter, diffJsonReport());

    expect(array_keys($data))->toBe(['schema', 'tool', 'mode', 'base', 'changed_files', 'score', 'summary', 'new', 'existing', 'resolved', 'errors'])
        ->and($data['schema'])->toBe(1)
        ->and($data['tool'])->toBe('sloppy')
        ->and($data['mode'])->toBe('diff')
        ->and($data['base'])->toBe('main')
        ->and($data['changed_files'])->toBe([
            ['path' => 'app/Order.php', 'status' => 'modified', 'changed_lines' => 6],
            ['path' => 'app/Invoice.php', 'status' => 'added', 'changed_lines' => 20],
        ]);
});

it('reports the score before and after, and the change between them', function (): void {
    $score = decodedDiffJson(new DiffJsonFormatter, diffJsonReport())['score'];

    expect(array_keys($score))->toBe(['base', 'current', 'delta'])
        ->and($score['base'])->toBe(['value' => 95, 'band' => 'clean', 'label' => ScoreBand::Clean->label(), 'penalty' => 1.0, 'penalty_density' => 0.5])
        ->and($score['current'])->toBe(['value' => 91, 'band' => 'healthy', 'label' => ScoreBand::Healthy->label(), 'penalty' => 4.25, 'penalty_density' => 2.125])
        ->and($score['delta'])->toBe(-4);
});

it('splits findings into new, existing and resolved, counted in the summary', function (): void {
    $data = decodedDiffJson(new DiffJsonFormatter, diffJsonReport());

    expect($data['summary'])->toBe(['new' => 1, 'existing' => 1, 'resolved' => 0])
        ->and($data['new'])->toHaveCount(1)
        ->and($data['new'][0]['rule'])->toBe('SL107')
        ->and($data['existing'][0]['rule'])->toBe('SL101')
        ->and($data['resolved'])->toBe([]);
});

it('gives every finding the same row a scan gives it, risk and tier included', function (): void {
    $row = decodedDiffJson(new DiffJsonFormatter, diffJsonReport())['new'][0];

    expect(array_keys($row))->toBe([
        'rule', 'name', 'category', 'severity', 'confidence', 'file', 'line', 'end_line', 'column',
        'message', 'explanation', 'suggestion', 'fingerprint', 'identity', 'metrics', 'risk', 'tier',
    ])
        ->and($row['identity'])->toBe(diffJsonReport()->new[0]->identity())
        ->and($row['risk'])->toBeFloat()
        ->and($row['tier'])->toBeIn(['defect', 'maintainability', 'advisory']);
});

it('adds the risk arithmetic only when asked', function (): void {
    $plain = decodedDiffJson(new DiffJsonFormatter, diffJsonReport())['new'][0];
    $explained = decodedDiffJson(new DiffJsonFormatter(explainRisk: true), diffJsonReport())['existing'][0];

    expect($plain)->not->toHaveKey('risk_factors')
        ->and($plain)->not->toHaveKey('risk_arithmetic')
        ->and($explained)->toHaveKey('risk_factors')
        ->and($explained['risk_arithmetic'])->toBeString();
});

it('writes errors as an object, even when there are none', function (): void {
    $empty = (new DiffJsonFormatter)->format(diffJsonReport());
    $some = decodedDiffJson(new DiffJsonFormatter, diffJsonReport(['app/Broken.php' => 'Syntax error']));

    expect($empty)->toContain('"errors": {}')
        ->and($some['errors'])->toBe(['app/Broken.php' => 'Syntax error']);
});

it('pretty-prints by default, compacts on request, and always ends in a newline', function (): void {
    $pretty = (new DiffJsonFormatter)->format(diffJsonReport());
    $compact = (new DiffJsonFormatter(pretty: false))->format(diffJsonReport());

    expect($pretty)->toContain("\n    \"tool\": \"sloppy\"")
        ->and($pretty)->toEndWith("}\n")
        ->and(substr_count($compact, "\n"))->toBe(1)
        ->and($compact)->toEndWith("}\n")
        ->and(json_decode($compact, true))->toBe(json_decode($pretty, true));
});

it('leaves slashes and unicode unescaped and keeps whole floats as floats', function (): void {
    $json = (new DiffJsonFormatter)->format(diffJsonReport());

    expect($json)->toContain('"app/Order.php"')
        ->and($json)->not->toContain('app\/Order.php')
        ->and($json)->toContain('"penalty": 1.0');
});

it('produces byte-identical output for the same report', function (): void {
    expect((new DiffJsonFormatter)->format(diffJsonReport()))->toBe((new DiffJsonFormatter)->format(diffJsonReport()));
});
