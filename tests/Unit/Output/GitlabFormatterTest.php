<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\Category;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Output\GitlabFormatter;

/**
 * @return list<array<string, mixed>>
 */
function codeQuality(string $report): array
{
    /** @var list<array<string, mixed>> $decoded */
    $decoded = json_decode($report, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('emits an empty array when there is nothing to report', function (): void {
    expect(codeQuality((new GitlabFormatter)->format(analysisResult())))->toBe([]);
});

it('describes a finding the way GitLab reads it', function (): void {
    $finding = finding(rule: 'SL107', line: 12, endLine: 20, severity: Severity::Critical, category: Category::ErrorHandling);
    $issues = codeQuality((new GitlabFormatter)->format(analysisResult([$finding])));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['type'])->toBe('issue')
        ->and($issues[0]['check_name'])->toBe('SL107')
        ->and($issues[0]['description'])->toBe('God Method: A message.')
        ->and($issues[0]['severity'])->toBe('blocker')
        ->and($issues[0]['categories'])->toBe(['Bug Risk'])
        ->and($issues[0]['fingerprint'])->toBe($finding->identity())
        ->and($issues[0]['location'])->toBe([
            'path' => 'app/Order.php',
            'lines' => ['begin' => 12, 'end' => 20],
        ]);
});

it('ends a finding on its own line when it has no range', function (): void {
    $issues = codeQuality((new GitlabFormatter)->format(analysisResult([finding(line: 7)])));

    expect($issues[0]['location'])->toBe(['path' => 'app/Order.php', 'lines' => ['begin' => 7, 'end' => 7]]);
});

it('maps every severity onto one GitLab knows', function (): void {
    $findings = [];

    foreach (Severity::cases() as $index => $severity) {
        $findings[] = finding(line: $index + 1, fingerprint: 'Order::m'.$index, severity: $severity);
    }

    $severities = array_column(codeQuality((new GitlabFormatter)->format(analysisResult($findings))), 'severity');

    expect($severities)->toBe(['blocker', 'critical', 'major', 'minor', 'info']);
});

it('maps every category onto one Code Climate defines', function (): void {
    $findings = [];

    foreach (Category::cases() as $index => $category) {
        $findings[] = finding(line: $index + 1, fingerprint: 'Order::m'.$index, category: $category);
    }

    $categories = array_column(codeQuality((new GitlabFormatter)->format(analysisResult($findings))), 'categories');
    $allowed = ['Bug Risk', 'Clarity', 'Compatibility', 'Complexity', 'Duplication', 'Performance', 'Security', 'Style'];

    foreach ($categories as $pair) {
        expect($pair)->toHaveCount(1)
            ->and($allowed)->toContain($pair[0]);
    }

    expect($categories)->toHaveCount(count(Category::cases()));
});

it('writes compact JSON when asked not to pretty-print', function (): void {
    $report = (new GitlabFormatter(pretty: false))->format(analysisResult([finding()]));

    expect($report)->not->toContain("\n    ")
        ->and(codeQuality($report))->toHaveCount(1);
});
