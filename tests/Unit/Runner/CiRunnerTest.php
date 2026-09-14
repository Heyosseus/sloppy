<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\CiOptions;
use Heyosseus\Sloppy\Runner\CiRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * A project with one god method, outside git, for the scan-mode paths.
 *
 * @param  array<string, mixed>  $config
 * @return array{0: Sloppy, 1: string}
 */
function ciProject(array $config = []): array
{
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Bad.php' => godMethodSource(),
    ]);

    return [new Sloppy(Configuration::fromArray([...['paths' => ['src']], ...$config], $root)), $root];
}

it('says nothing and succeeds when sloppy is disabled', function (): void {
    [$sloppy, $root] = ciProject(['enabled' => false]);
    $output = new RecordingRunnerOutput;

    expect((new CiRunner)->run($sloppy, new CiOptions, $output))->toBe(ExitCode::Success)
        ->and($output->messages())->toContain('info: Sloppy is disabled (sloppy.enabled is false).');

    removeTree($root);
});

it('reports a bad option rather than running', function (): void {
    [$sloppy, $root] = ciProject();
    $output = new RecordingRunnerOutput;

    $code = (new CiRunner)->run($sloppy, new CiOptions(minConfidence: 500), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toContain('--min-confidence must be a number between 0 and 100.');

    removeTree($root);
});

it('scans the whole project outside a git checkout, in console form', function (): void {
    [$sloppy, $root] = ciProject(['fail_on' => 'never']);
    $output = new RecordingRunnerOutput;

    $code = (new CiRunner)->run($sloppy, new CiOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->messages())->toContain('notice: Detected no recognised CI; reporting as console.')
        ->and($output->messages())->toContain('notice: Scanning the whole project: this is not a git checkout.')
        ->and($output->reports()[0][0])->toBe('console')
        ->and($output->reportBody())->toContain('/100');

    removeTree($root);
});

it('fails the step when a scan finds something at the threshold', function (): void {
    [$sloppy, $root] = ciProject(['fail_on' => 'medium']);
    $output = new RecordingRunnerOutput;

    expect((new CiRunner)->run($sloppy, new CiOptions, $output))->toBe(ExitCode::FindingsAboveThreshold);

    removeTree($root);
});

it('writes a Code Quality artifact and a readable log on GitLab', function (): void {
    [$sloppy, $root] = ciProject(['fail_on' => 'never']);
    $output = new RecordingRunnerOutput;
    $environment = new CiEnvironment(['GITLAB_CI' => 'true', 'CI_MERGE_REQUEST_TARGET_BRANCH_NAME' => 'main']);

    $code = (new CiRunner($environment))->run($sloppy, new CiOptions(report: 'gl-code-quality-report.json'), $output);

    /** @var list<array<string, mixed>> $issues */
    $issues = json_decode((string) file_get_contents($root.'/gl-code-quality-report.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->messages())->toContain('notice: Detected GitLab CI; reporting as gitlab.')
        ->and($output->messages())->toContain('notice: Scanning the whole project: GitLab compares Code Quality reports itself.')
        ->and($output->messages())->toContain('notice: Wrote the gitlab report to gl-code-quality-report.json.')
        ->and($issues)->not->toBeEmpty()
        ->and($issues[0]['check_name'])->toBe('SL101')
        // The log still shows the human-readable report, so the job is
        // readable without downloading the artifact.
        ->and($output->reports()[0][0])->toBe('console');

    removeTree($root);
});

it('reports a report file it could not write', function (): void {
    [$sloppy, $root] = ciProject();
    $output = new RecordingRunnerOutput;

    $code = (new CiRunner)->run($sloppy, new CiOptions(report: 'missing-directory/report.json'), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages())->toContain(sprintf('error: Could not write the report to %s/missing-directory/report.json.', $root));

    removeTree($root);
});

it('annotates the new findings and hands the numbers back to the workflow', function (): void {
    $repository = TempRepository::create()
        ->write('composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}')
        ->write('src/Fine.php', "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n")
        ->commit('first')
        ->write('src/Bad.php', godMethodSource());

    $summary = $repository->path.'/summary.md';
    $outputs = $repository->path.'/outputs.txt';

    $environment = new CiEnvironment([
        'GITHUB_ACTIONS' => 'true',
        'GITHUB_BASE_REF' => 'main',
        'GITHUB_STEP_SUMMARY' => $summary,
        'GITHUB_OUTPUT' => $outputs,
    ]);

    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['src'], 'fail_on' => 'medium'], $repository->path));
    $output = new RecordingRunnerOutput;

    $code = (new CiRunner($environment))->run($sloppy, new CiOptions, $output);
    $written = (string) file_get_contents($outputs);

    expect($code)->toBe(ExitCode::FindingsAboveThreshold)
        ->and($output->messages())->toContain('notice: Detected GitHub Actions; reporting as github.')
        ->and($output->reports()[0][0])->toBe('github')
        ->and($output->reportBody())->toContain('::error file=src/Bad.php')
        ->and($output->reportBody())->toContain('::notice title=Sloppy diff::Against main:')
        ->and($output->messages())->toContain('notice: Wrote the job summary.')
        ->and(file_get_contents($summary))->toContain('Sloppy')
        ->and($written)->toContain('mode=diff')
        ->and($written)->toContain('base=main')
        ->and($written)->toContain('new-findings=1')
        ->and($written)->toContain('status=failed')
        ->and($written)->toContain('score=');

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('renders a diff in whichever format it was told to use', function (): void {
    $repository = TempRepository::create()
        ->write('composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}')
        ->write('src/Fine.php', "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n")
        ->commit('first')
        ->write('src/Bad.php', godMethodSource());

    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['src'], 'fail_on' => 'never'], $repository->path));

    $bodies = [];

    foreach ([OutputFormat::Json, OutputFormat::Markdown, OutputFormat::Console, OutputFormat::Sarif, OutputFormat::Gitlab, OutputFormat::Rector] as $format) {
        $output = new RecordingRunnerOutput;
        $code = (new CiRunner)->run($sloppy, new CiOptions(base: 'HEAD', format: $format), $output);

        expect($code)->toBe(ExitCode::Success);

        $bodies[$format->value] = $output->reportBody();
    }

    expect($bodies['json'])->toContain('"mode": "diff"')
        ->and($bodies['markdown'])->toContain('##')
        ->and($bodies['console'])->toContain('Read in this order')
        ->and($bodies['sarif'])->toContain('"$schema"')
        ->and($bodies['gitlab'])->toContain('"check_name": "SL101"')
        ->and($bodies['rector'])->toContain('RectorConfig');

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('scans instead when the requested revision does not exist', function (): void {
    $repository = TempRepository::create()
        ->write('composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}')
        ->write('src/Bad.php', godMethodSource())
        ->commit('first');

    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['src'], 'fail_on' => 'never'], $repository->path));
    $output = new RecordingRunnerOutput;

    $code = (new CiRunner)->run($sloppy, new CiOptions(base: 'release-1.0'), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->messages())->toContain('notice: Scanning the whole project: none of [release-1.0, origin/release-1.0] resolve here.');

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('scans when told to, even inside a pull request', function (): void {
    $repository = TempRepository::create()
        ->write('composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}')
        ->write('src/Bad.php', godMethodSource())
        ->commit('first');

    $environment = new CiEnvironment(['GITHUB_ACTIONS' => 'true', 'GITHUB_BASE_REF' => 'main']);
    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['src'], 'fail_on' => 'never'], $repository->path));
    $output = new RecordingRunnerOutput;

    $code = (new CiRunner($environment))->run($sloppy, new CiOptions(scan: true), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->reportBody())->toContain('::notice title=Sloppy score::');

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('reports that it could not write the summary or the outputs', function (): void {
    [$sloppy, $root] = ciProject(['fail_on' => 'never']);
    $output = new RecordingRunnerOutput;

    $environment = new CiEnvironment([
        'GITHUB_STEP_SUMMARY' => $root.'/nope/summary.md',
        'GITHUB_OUTPUT' => $root.'/nope/outputs.txt',
    ]);

    $code = (new CiRunner($environment))->run($sloppy, new CiOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->messages())->toContain(sprintf('warn: Could not write the job summary to %s/nope/summary.md.', $root))
        ->and($output->messages())->toContain(sprintf('warn: Could not write step outputs to %s/nope/outputs.txt.', $root));

    removeTree($root);
});

it('leaves the job summary alone when asked to', function (): void {
    [$sloppy, $root] = ciProject(['fail_on' => 'never']);
    $output = new RecordingRunnerOutput;
    $summary = $root.'/summary.md';

    $code = (new CiRunner(new CiEnvironment(['GITHUB_STEP_SUMMARY' => $summary])))
        ->run($sloppy, new CiOptions(summary: false), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and(is_file($summary))->toBeFalse();

    removeTree($root);
});

it('reports every finding when the baseline is turned off', function (): void {
    [$sloppy, $root] = ciProject(['fail_on' => 'never']);

    file_put_contents($root.'/.sloppy-baseline.json', (string) json_encode([
        'schema' => 1,
        'generated_at' => '2026-01-01T00:00:00+00:00',
        'findings' => [],
    ]));

    $baselined = new RecordingRunnerOutput;
    $everything = new RecordingRunnerOutput;

    (new CiRunner)->run($sloppy, new CiOptions, $baselined);
    (new CiRunner)->run($sloppy, new CiOptions(noBaseline: true), $everything);

    expect($baselined->reportBody())->toContain('SL101')
        ->and($everything->reportBody())->toContain('SL101');

    removeTree($root);
});

it('surfaces an analysis failure as an error', function (): void {
    $root = tempProject(['composer.json' => '{}']);
    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['src'],
        'baseline' => 'broken.json',
    ], $root));

    mkdir($root.'/src');
    file_put_contents($root.'/src/Fine.php', "<?php\n\nclass Fine {}\n");
    file_put_contents($root.'/broken.json', 'not json');

    $output = new RecordingRunnerOutput;

    expect((new CiRunner)->run($sloppy, new CiOptions, $output))->toBe(ExitCode::Error)
        ->and(implode("\n", $output->messages()))->toContain('is not valid JSON');

    removeTree($root);
});
