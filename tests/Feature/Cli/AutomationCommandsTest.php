<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SloppyApplication;
use Heyosseus\Sloppy\Runner\ExitCode;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * A tester over a fresh application, the way a user's shell sees it.
 */
function automationTester(): ApplicationTester
{
    $application = new SloppyApplication('test');
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);

    return new ApplicationTester($application);
}

/**
 * Everything the command said, on either stream.
 *
 * Errors go to standard output through Symfony's style, while the project-root
 * notice goes to standard error so it cannot land inside a machine-readable
 * report -- so a test about a message should look at both.
 */
function output(ApplicationTester $tester): string
{
    return $tester->getDisplay().$tester->getErrorOutput();
}

/**
 * A project with one god method in it.
 *
 * @param  array<string, string>  $extra
 */
function automationProject(array $extra = []): string
{
    return tempProject([
        'composer.json' => '{"require":{"laravel/framework":"^12.0"},"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'sloppy.php' => "<?php\n\nreturn ['paths' => ['src'], 'fail_on' => 'high'];\n",
        'src/Bad.php' => godMethodSource(),
        ...$extra,
    ]);
}

it('registers every command, with scan still the default', function (): void {
    $application = new SloppyApplication('test');

    foreach (['scan', 'diff', 'review', 'baseline', 'ci', 'fix', 'health', 'rules', 'mcp', 'guide'] as $name) {
        expect($application->has($name))->toBeTrue(sprintf('The %s command is missing.', $name));
    }

    expect($application->get('ci')->getDescription())->toContain('CI system');
});

it('runs a CI report from the command line', function (): void {
    $project = automationProject();
    $tester = automationTester();

    $code = $tester->run([
        'command' => 'ci',
        '--project' => $project,
        '--format' => 'gitlab',
        '--fail-on' => 'never',
    ], ['capture_stderr_separately' => true]);

    /** @var list<array<string, mixed>> $issues */
    $issues = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(ExitCode::Success->value)
        ->and($issues[0]['check_name'])->toBe('SL101');

    removeTree($project);
});

it('fails the CI step on a finding at the threshold', function (): void {
    $project = automationProject();
    $tester = automationTester();

    $code = $tester->run([
        'command' => 'ci',
        '--project' => $project,
        '--format' => 'github',
    ], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::FindingsAboveThreshold->value)
        ->and($tester->getDisplay())->toContain('::error file=src/Bad.php');

    removeTree($project);
});

it('reports an unknown format rather than running', function (): void {
    $project = automationProject();
    $tester = automationTester();

    $code = $tester->run([
        'command' => 'ci',
        '--project' => $project,
        '--format' => 'xml',
    ], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Error->value)
        ->and(output($tester))->toContain('Unknown --format [xml]');

    removeTree($project);
});

it('writes a health snapshot from the command line', function (): void {
    $project = automationProject();
    $tester = automationTester();

    $code = $tester->run([
        'command' => 'health',
        '--project' => $project,
        '--json' => true,
    ], ['capture_stderr_separately' => true]);

    /** @var array{score: int, findings: int} $snapshot */
    $snapshot = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(ExitCode::Success->value)
        ->and($snapshot['findings'])->toBe(1)
        ->and($snapshot['score'])->toBeLessThan(100);

    removeTree($project);
});

it('writes agent rules from the command line', function (): void {
    $project = automationProject();
    $tester = automationTester();

    $code = $tester->run([
        'command' => 'rules',
        '--project' => $project,
        '--format' => ['claude', 'cursor'],
    ], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Success->value)
        ->and(file_get_contents($project.'/CLAUDE.md'))->toContain('### SL101 God Method')
        ->and(is_file($project.'/.cursorrules'))->toBeTrue();

    removeTree($project);
});

it('prints the rules without writing when asked', function (): void {
    $project = automationProject();
    $tester = automationTester();

    $code = $tester->run([
        'command' => 'rules',
        '--project' => $project,
        '--stdout' => true,
    ], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Success->value)
        ->and($tester->getDisplay())->toContain('# Sloppy: code rules for this repository')
        ->and(is_file($project.'/CLAUDE.md'))->toBeFalse();

    removeTree($project);
});

it('rejects a ruleset format nobody reads', function (): void {
    $project = automationProject();
    $tester = automationTester();

    $code = $tester->run([
        'command' => 'rules',
        '--project' => $project,
        '--format' => ['emacs'],
    ], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Error->value)
        ->and(output($tester))->toContain('Unknown ruleset format [emacs]');

    removeTree($project);
});

it('generates a Rector configuration and says what is left for a person', function (): void {
    $project = automationProject();
    $tester = automationTester();

    $code = $tester->run([
        'command' => 'fix',
        '--project' => $project,
        '--dry-run' => true,
    ], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Success->value)
        ->and(output($tester))->toContain('1 finding(s) need a person: SL101 x1.');

    removeTree($project);
});

it('reports a non-numeric option rather than running', function (): void {
    $project = automationProject();
    $tester = automationTester();

    $code = $tester->run([
        'command' => 'health',
        '--project' => $project,
        '--top' => 'lots',
    ], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Error->value)
        ->and(output($tester))->toContain('--top must be a number.');

    removeTree($project);
});

it('reports a project directory that is not there', function (): void {
    $tester = automationTester();

    $code = $tester->run([
        'command' => 'ci',
        '--project' => '/nope/not/a/project',
    ], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Error->value)
        ->and(output($tester))->toContain('does not exist');
});

it('explains every command without locating a project first', function (): void {
    $tester = automationTester();

    $exit = $tester->run(['command' => 'guide'], ['capture_stderr_separately' => true]);

    expect($exit)->toBe(ExitCode::Success->value)
        ->and(output($tester))->toContain('Every day')
        ->and(output($tester))->toContain('php artisan sloppy:ci')
        // No project root notice, because no project was located: `guide` is
        // the command someone runs before there is a project to analyse.
        ->and(output($tester))->not->toContain('Project root:');
});
