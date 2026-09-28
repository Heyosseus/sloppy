<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\ScanOptions;
use Heyosseus\Sloppy\Runner\ScanRunner;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

$fixtures = dirname(__DIR__, 2).'/Fixtures';

it('says nothing and succeeds when sloppy is disabled', function () use ($fixtures): void {
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray(['enabled' => false], $fixtures));

    $code = (new ScanRunner)->run($sloppy, new ScanOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->messages())->toBe(['info: Sloppy is disabled (sloppy.enabled is false).']);
});

it('names the skipped rules when a missing framework is the reason none are enabled', function () use ($fixtures): void {
    $output = new RecordingRunnerOutput;
    // $fixtures has no composer.json, so the ten Laravel rules are skipped for
    // a missing framework before --rule even gets a chance to matter.
    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['Sloppy']], $fixtures));

    $code = (new ScanRunner)->run($sloppy, new ScanOptions(rules: ['SL999']), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages())->toBe([
            'error: No rules are enabled: 10 rule(s) were skipped for a missing framework '
            .'(sloppy.framework: auto). Check sloppy.rules and any --rule filter.',
        ]);
});

it('errors when every rule is filtered out and none were skipped for a framework', function () use ($fixtures): void {
    $output = new RecordingRunnerOutput;
    // Framework pinned explicitly, so nothing is skipped and --rule=SL999 is
    // the only possible cause of an empty registry.
    $sloppy = new Sloppy(Configuration::fromArray(['framework' => 'laravel', 'paths' => ['Sloppy']], $fixtures));

    $code = (new ScanRunner)->run($sloppy, new ScanOptions(rules: ['SL999']), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages())->toBe([
            'error: No rules are enabled. Check sloppy.rules and any --rule filter.',
        ]);
});

it('warns and succeeds when the configured paths hold no PHP', function () use ($fixtures): void {
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['Nope']], $fixtures));

    $code = (new ScanRunner)->run($sloppy, new ScanOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->messages())->toBe(['warn: No PHP files found in: Nope.']);
});

it('reports findings as json and fails on the configured threshold', function () use ($fixtures): void {
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['Sloppy'],
        'fail_on' => 'medium',
        'baseline' => $fixtures.'/does-not-exist.json',
    ], $fixtures));

    $code = (new ScanRunner)->run($sloppy, new ScanOptions(format: OutputFormat::Json), $output);

    /** @var array{schema: int, findings: list<array<string, mixed>>} $decoded */
    $decoded = json_decode($output->reportBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(ExitCode::FindingsAboveThreshold)
        ->and($decoded['schema'])->toBe(1)
        ->and($decoded['findings'])->not->toBeEmpty();
});

it('succeeds when the threshold is disabled even with findings', function () use ($fixtures): void {
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['Sloppy'],
        'baseline' => $fixtures.'/does-not-exist.json',
    ], $fixtures));

    $code = (new ScanRunner)->run($sloppy, new ScanOptions(failOn: 'never'), $output);

    expect($code)->toBe(ExitCode::Success);
});

it('turns a bad option into an error rather than an exception', function () use ($fixtures): void {
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['Sloppy']], $fixtures));

    $code = (new ScanRunner)->run($sloppy, new ScanOptions(minConfidence: 200), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages())->toBe(['error: --min-confidence must be a number between 0 and 100.']);
});

it('drives a progress bar for a console run over the file threshold', function (): void {
    $files = ['composer.json' => '{}'];

    for ($i = 1; $i <= 51; $i++) {
        $files[sprintf('app/Thing%d.php', $i)] = sprintf("<?php\n\nclass Thing%d\n{\n}\n", $i);
    }

    $project = tempProject($files);
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['app'],
        'baseline' => $project.'/does-not-exist.json',
    ], $project));

    $code = (new ScanRunner)->run($sloppy, new ScanOptions(failOn: 'never'), $output);

    // The exact sequence, not just its shape: one advance per analysed file.
    // A bar that advanced once, or once per class, would satisfy a
    // toContain('advance') check just as well as a correct one.
    $expected = ['start:51'];

    for ($i = 1; $i <= 51; $i++) {
        $expected[] = 'advance';
    }

    $expected[] = 'finish';

    expect($code)->toBe(ExitCode::Success)
        ->and($output->progressEvents())->toBe($expected);

    removeTree($project);
});

it('shows no progress bar for a machine-readable run over the threshold', function (): void {
    $files = ['composer.json' => '{}'];

    for ($i = 1; $i <= 51; $i++) {
        $files[sprintf('app/Thing%d.php', $i)] = sprintf("<?php\n\nclass Thing%d\n{\n}\n", $i);
    }

    $project = tempProject($files);
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['app'],
        'baseline' => $project.'/does-not-exist.json',
    ], $project));

    (new ScanRunner)->run($sloppy, new ScanOptions(format: OutputFormat::Json, failOn: 'never'), $output);

    expect($output->progressEvents())->toBe([]);

    removeTree($project);
});

/**
 * @param  array<string, mixed>  $config
 */
function triagedScan(string $fixtures, string $baseline, array $config = []): Sloppy
{
    return new Sloppy(Configuration::fromArray([
        'paths' => ['Sloppy'],
        'fail_on' => 'high',
        'baseline' => $baseline,
        ...$config,
    ], $fixtures));
}

it('triages a run too long to read, and lists everything when asked', function () use ($fixtures): void {
    $baseline = tempProject().'/.sloppy-baseline.json';

    $triaged = new RecordingRunnerOutput;
    (new ScanRunner)->run(triagedScan($fixtures, $baseline), new ScanOptions(top: 3), $triaged);

    $all = new RecordingRunnerOutput;
    (new ScanRunner)->run(triagedScan($fixtures, $baseline), new ScanOptions(all: true, top: 3), $all);

    $oneRule = new RecordingRunnerOutput;
    (new ScanRunner)->run(triagedScan($fixtures, $baseline), new ScanOptions(rules: ['SL101'], top: 1), $oneRule);

    expect($triaged->reportBody())->toContain('Fix first')->toContain('By rule')->toContain('No baseline yet')
        ->and($all->reportBody())->not->toContain('Fix first')
        ->and($oneRule->reportBody())->not->toContain('Fix first')
        // Nobody answered, so nothing was written.
        ->and($triaged->messages())->toContain('confirm: Accept these '.substr_count($all->reportBody(), 'confidence)').' findings as a baseline (.sloppy-baseline.json), so scans report only new ones from now on?')
        ->and(is_file($baseline))->toBeFalse();

    removeTree(dirname($baseline));
});

it('writes the baseline when the person at the terminal accepts it, and passes', function () use ($fixtures): void {
    $baseline = tempProject().'/.sloppy-baseline.json';
    $output = new RecordingRunnerOutput(answers: [true]);

    $code = (new ScanRunner)->run(triagedScan($fixtures, $baseline), new ScanOptions(top: 3), $output);

    $again = new RecordingRunnerOutput;
    (new ScanRunner)->run(triagedScan($fixtures, $baseline), new ScanOptions(top: 3), $again);

    expect($code)->toBe(ExitCode::Success)
        ->and(is_file($baseline))->toBeTrue()
        ->and(implode("\n", $output->messages()))->toContain('Commit it, and every scan from here reports only what is new.')
        // The next scan sees the baseline and has nothing new to show or ask.
        ->and($again->reportBody())->toContain('Nothing flagged.')
        ->and(implode("\n", $again->messages()))->not->toContain('confirm:');

    removeTree(dirname($baseline));
});

it('never offers a baseline to a machine-readable report', function () use ($fixtures): void {
    $baseline = tempProject().'/.sloppy-baseline.json';
    $output = new RecordingRunnerOutput(answers: [true]);

    (new ScanRunner)->run(triagedScan($fixtures, $baseline), new ScanOptions(format: OutputFormat::Json, top: 3), $output);

    expect(implode("\n", $output->messages()))->not->toContain('confirm:')
        ->and(is_file($baseline))->toBeFalse();

    removeTree(dirname($baseline));
});
