<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Baseline\Baseline;
use Heyosseus\Sloppy\Baseline\BaselineEntry;
use Heyosseus\Sloppy\Baseline\BaselineManager;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\BaselineOptions;
use Heyosseus\Sloppy\Runner\BaselineRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

$fixtures = dirname(__DIR__, 2).'/Fixtures';

it('writes a baseline and says what it recorded', function () use ($fixtures): void {
    $path = sys_get_temp_dir().'/sloppy-baseline-'.bin2hex(random_bytes(4)).'.json';
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['Sloppy'],
        'baseline' => $path,
    ], $fixtures));

    try {
        // The number of findings this run of the real analyzer is expected to
        // produce, worked out independently of the baseline runner so the
        // assertion below cannot be satisfied by a runner that quietly writes
        // an empty or truncated baseline.
        $expected = $sloppy->analyze();

        $code = (new BaselineRunner)->run($sloppy, new BaselineOptions, $output);

        expect($code)->toBe(ExitCode::Success)
            ->and(file_exists($path))->toBeTrue()
            ->and(implode("\n", $output->messages()))->toContain('Baselined');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $entries */
        $entries = $decoded['entries'];
        $summedCount = array_sum(array_column($entries, 'count'));

        expect($decoded)->toHaveKeys(['schema', 'tool', 'generated_at', 'score', 'total', 'entries'])
            ->and($decoded['schema'])->toBe(1)
            ->and($decoded['tool'])->toBe('sloppy')
            ->and($decoded['total'])->toBeGreaterThan(0)
            ->and($decoded['total'])->toBe(count($expected->findings))
            ->and($entries)->not->toBeEmpty()
            ->and($summedCount)->toBe($decoded['total']);

        foreach ($entries as $entry) {
            expect($entry)->toHaveKeys(['id', 'rule', 'file', 'fingerprint', 'count', 'message']);
        }
    } finally {
        @unlink($path);
    }
});

it('refuses to overwrite an existing baseline without force', function () use ($fixtures): void {
    $path = sys_get_temp_dir().'/sloppy-baseline-'.bin2hex(random_bytes(4)).'.json';
    $original = '{"schema":1,"findings":[]}';
    file_put_contents($path, $original);

    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['Sloppy'],
        'baseline' => $path,
    ], $fixtures));

    try {
        $code = (new BaselineRunner)->run($sloppy, new BaselineOptions, $output);

        expect($code)->toBe(ExitCode::Error)
            ->and(implode("\n", $output->messages()))->toContain('--force')
            ->and(file_get_contents($path))->toBe($original);
    } finally {
        @unlink($path);
    }
});

it('overwrites and reports the delta when forced', function () use ($fixtures): void {
    $path = sys_get_temp_dir().'/sloppy-baseline-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($path, '{"schema":1,"findings":[]}');

    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['Sloppy'],
        'baseline' => $path,
    ], $fixtures));

    try {
        $code = (new BaselineRunner)->run($sloppy, new BaselineOptions(force: true), $output);

        expect($code)->toBe(ExitCode::Success)
            ->and(implode("\n", $output->messages()))->toContain('Previous baseline held 0 finding(s)');
    } finally {
        @unlink($path);
    }
});

it('renders a negative delta when the new baseline holds fewer findings than the old one', function (): void {
    $project = tempProject(['app/Bad.php' => godMethodSource()]);
    $path = $project.'/.sloppy-baseline.json';

    // Three dummy entries the current code no longer trips, so the new
    // baseline (one God Method finding) is smaller than the old one, and the
    // `%+d` branch of BaselineRunner::report() renders a negative delta
    // rather than the "no change" string the other cases exercise.
    $previous = new Baseline([
        'a' => new BaselineEntry(id: 'a', ruleId: 'SL999', file: 'app/One.php', fingerprint: 'one'),
        'b' => new BaselineEntry(id: 'b', ruleId: 'SL999', file: 'app/Two.php', fingerprint: 'two'),
        'c' => new BaselineEntry(id: 'c', ruleId: 'SL999', file: 'app/Three.php', fingerprint: 'three'),
    ]);
    (new BaselineManager)->save($previous, $path);

    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray(['baseline' => $path], $project));

    try {
        $code = (new BaselineRunner)->run($sloppy, new BaselineOptions(force: true), $output);

        expect($code)->toBe(ExitCode::Success)
            ->and(implode("\n", $output->messages()))->toContain('Previous baseline held 3 finding(s) (-2).');
    } finally {
        removeTree($project);
    }
});
