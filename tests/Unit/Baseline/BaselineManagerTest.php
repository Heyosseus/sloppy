<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Analysis\AnalysisResult;
use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Baseline\Baseline;
use Heyosseus\Sloppy\Baseline\BaselineEntry;
use Heyosseus\Sloppy\Baseline\BaselineManager;
use Heyosseus\Sloppy\Scoring\ScoreCalculator;

function baselineFile(): string
{
    return str_replace('\\', '/', sys_get_temp_dir()).'/sloppy-baseline-'.bin2hex(random_bytes(6)).'/.sloppy-baseline.json';
}

it('records the current findings', function (): void {
    $result = AnalysisResult::create(
        [finding(fingerprint: 'a'), finding(fingerprint: 'b')],
        ['app/A.php'],
        1000,
        new ScoreCalculator,
    );

    $baseline = (new BaselineManager)->create($result, '2026-01-01T00:00:00+00:00');

    expect($baseline->count())->toBe(2)
        ->and($baseline->generatedAt)->toBe('2026-01-01T00:00:00+00:00')
        ->and($baseline->score)->toBe($result->score->value)
        ->and($baseline->isEmpty())->toBeFalse();
});

it('hides baselined findings and surfaces new ones', function (): void {
    $manager = new BaselineManager;
    $existing = finding(fingerprint: 'existing');
    $baseline = Baseline::fromFindings([$existing]);

    $partition = $manager->partition([$existing, finding(fingerprint: 'brand-new')], $baseline);

    expect($partition['baselined'])->toHaveCount(1)
        ->and($partition['new'])->toHaveCount(1)
        ->and($partition['new'][0]->fingerprint)->toBe('brand-new');
});

it('treats occurrences beyond the recorded count as new', function (): void {
    $manager = new BaselineManager;
    $one = finding(fingerprint: 'same');
    $baseline = Baseline::fromFindings([$one]);

    // One was accepted; three are present now, so two of them are new.
    $partition = $manager->partition([$one, $one, $one], $baseline);

    expect($partition['baselined'])->toHaveCount(1)
        ->and($partition['new'])->toHaveCount(2);
});

it('counts repeats when the baseline is created', function (): void {
    $baseline = Baseline::fromFindings([finding(fingerprint: 'same'), finding(fingerprint: 'same')]);

    expect($baseline->count())->toBe(2)
        ->and($baseline->entries())->toHaveCount(1)
        ->and($baseline->entries()[0]->count)->toBe(2);
});

it('treats everything as new when there is no baseline', function (): void {
    $partition = (new BaselineManager)->partition([finding()], null);

    expect($partition['new'])->toHaveCount(1)
        ->and($partition['baselined'])->toBe([]);
});

it('survives a finding moving to a different line', function (): void {
    // The whole point of a line-independent fingerprint.
    $baseline = Baseline::fromFindings([finding(line: 10)]);

    expect($baseline->contains(finding(line: 400)))->toBeTrue()
        ->and($baseline->allowanceFor(finding(line: 400)))->toBe(1)
        ->and($baseline->contains(finding(fingerprint: 'elsewhere')))->toBeFalse()
        ->and($baseline->allowanceFor(finding(fingerprint: 'elsewhere')))->toBe(0);
});

it('reports entries no finding matches any more', function (): void {
    $manager = new BaselineManager;
    $baseline = Baseline::fromFindings([finding(fingerprint: 'fixed'), finding(fingerprint: 'still-there')]);

    $resolved = $manager->resolved([finding(fingerprint: 'still-there')], $baseline);

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]->fingerprint)->toBe('fixed');
});

it('writes and reads a baseline round trip', function (): void {
    $manager = new BaselineManager;
    $path = baselineFile();
    $original = Baseline::fromFindings([finding(fingerprint: 'a'), finding(fingerprint: 'b')], '2026-01-01T00:00:00+00:00', 62);

    expect($manager->exists($path))->toBeFalse();

    $manager->save($original, $path);

    expect($manager->exists($path))->toBeTrue();

    $loaded = $manager->load($path);

    expect($loaded?->count())->toBe(2)
        ->and($loaded?->generatedAt)->toBe('2026-01-01T00:00:00+00:00')
        ->and($loaded?->score)->toBe(62)
        ->and($loaded?->contains(finding(fingerprint: 'a')))->toBeTrue();

    removeTree(dirname($path));
});

it('writes entries in a stable order', function (): void {
    $manager = new BaselineManager;
    $findings = [
        finding(rule: 'SL203', file: 'app/B.php', fingerprint: 'z'),
        finding(rule: 'SL101', file: 'app/A.php', fingerprint: 'a'),
        finding(rule: 'SL101', file: 'app/A.php', fingerprint: 'b'),
    ];

    $forwards = baselineFile();
    $backwards = baselineFile();

    $manager->save(Baseline::fromFindings($findings, 'fixed'), $forwards);
    $manager->save(Baseline::fromFindings(array_reverse($findings), 'fixed'), $backwards);

    expect(file_get_contents($forwards))->toBe(file_get_contents($backwards));

    removeTree(dirname($forwards));
    removeTree(dirname($backwards));
});

it('returns null when there is no baseline file', function (): void {
    expect((new BaselineManager)->load(baselineFile()))->toBeNull();
});

it('refuses to silently ignore a corrupt baseline', function (): void {
    // Treating it as empty would fail a build for reasons nobody could explain.
    $path = baselineFile();
    mkdir(dirname($path), 0o777, true);
    file_put_contents($path, '{not json');

    expect(fn (): ?Baseline => (new BaselineManager)->load($path))
        ->toThrow(RuntimeException::class, 'is not valid JSON');

    removeTree(dirname($path));
});

it('skips malformed entries when reading', function (): void {
    $baseline = Baseline::fromArray([
        'entries' => [
            ['id' => 'a', 'rule' => 'SL101', 'file' => 'app/A.php', 'fingerprint' => 'x', 'count' => 2],
            ['id' => 'b', 'rule' => 'SL101'],
            'nonsense',
            ['id' => 'c', 'rule' => 'SL101', 'file' => 'app/A.php', 'fingerprint' => 'y', 'count' => -1],
        ],
        'generated_at' => 5,
        'score' => 'nope',
    ]);

    expect($baseline->entries())->toHaveCount(2)
        ->and($baseline->count())->toBe(3)
        ->and($baseline->generatedAt)->toBe('')
        ->and($baseline->score)->toBeNull();
});

it('tolerates a baseline with no entries key', function (): void {
    expect(Baseline::fromArray([])->isEmpty())->toBeTrue()
        ->and(Baseline::fromArray(['entries' => 'nonsense'])->isEmpty())->toBeTrue();
});

it('exports the documented baseline shape', function (): void {
    $array = Baseline::fromFindings([finding()], 'fixed', 70)->toArray();

    expect($array)->toHaveKeys(['schema', 'tool', 'generated_at', 'score', 'total', 'entries'])
        ->and($array['schema'])->toBe(Baseline::SCHEMA)
        ->and($array['tool'])->toBe('sloppy')
        ->and($array['entries'][0])->toHaveKeys(['id', 'rule', 'file', 'fingerprint', 'count', 'message']);
});

it('builds an entry from a finding and can change its count', function (): void {
    $entry = BaselineEntry::fromFinding(finding(severity: Severity::High), 3);

    expect($entry->ruleId)->toBe('SL101')
        ->and($entry->count)->toBe(3)
        ->and($entry->withCount(1)->count)->toBe(1)
        ->and($entry->withCount(1)->id)->toBe($entry->id)
        ->and(BaselineEntry::fromArray(['id' => 'a']))->toBeNull();
});
