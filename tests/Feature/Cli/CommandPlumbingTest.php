<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\McpCliCommand;
use Heyosseus\Sloppy\Cli\ProjectLocator;
use Heyosseus\Sloppy\Cli\SloppyApplication;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\SloppyServiceProvider;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * A tester over a fresh application.
 */
function plumbingTester(): ApplicationTester
{
    $application = new SloppyApplication('test');
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);

    return new ApplicationTester($application);
}

it('accepts a numeric option and applies it', function (): void {
    $project = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'sloppy.php' => "<?php\n\nreturn ['paths' => ['src'], 'fail_on' => 'never'];\n",
        'src/Bad.php' => godMethodSource(),
    ]);

    $tester = plumbingTester();

    $code = $tester->run([
        'command' => 'health',
        '--project' => $project,
        '--json' => true,
        '--min-confidence' => '100',
        '--top' => '2',
    ], ['capture_stderr_separately' => true]);

    /** @var array{findings: int} $snapshot */
    $snapshot = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(ExitCode::Success->value)
        ->and($snapshot['findings'])->toBe(0);

    removeTree($project);
});

it('serves the protocol from the standalone binary', function (): void {
    $project = tempProject([
        'composer.json' => '{}',
        'in.jsonl' => '{"jsonrpc":"2.0","id":1,"method":"ping"}'."\n",
        'out.jsonl' => '',
    ]);

    // Real standard input would block on a terminal forever, which is why the
    // command takes its streams.
    $application = new SloppyApplication('test');
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);
    $command = new McpCliCommand(
        new SplFileObject($project.'/in.jsonl'),
        new SplFileObject($project.'/out.jsonl', 'w'),
    );

    $application->add($command);
    $tester = new ApplicationTester($application);

    $code = $tester->run([
        'command' => 'mcp',
        '--project' => $project,
    ], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Success->value)
        ->and(file_get_contents($project.'/out.jsonl'))->toContain('"id":1');

    // Windows will not delete a file that still has an open handle, and the
    // command is holding two of them.
    unset($tester, $application, $command);
    gc_collect_cycles();

    removeTree($project);
});

it('says when a project has no composer.json anywhere above it', function (): void {
    // Deeper than the locator will climb, so whatever else is on this machine
    // above the temporary directory cannot be found and mistaken for it.
    $project = tempProject(['a/b/c/d/e/f/g/h/i/j/k/l/m/n/keep.txt' => 'x']);

    expect(fn (): string => (new ProjectLocator)->locate(null, $project.'/a/b/c/d/e/f/g/h/i/j/k/l/m/n'))
        ->toThrow(RuntimeException::class, 'No composer.json found in');

    removeTree($project);
});

it('says when the working directory itself cannot be resolved', function (): void {
    expect(fn (): string => (new ProjectLocator)->locate(null, '/nowhere/at/all'))
        ->toThrow(RuntimeException::class, 'could not be resolved');
});

it('says when a configuration file does not exist', function (): void {
    $project = tempProject(['composer.json' => '{}']);
    $tester = plumbingTester();

    $code = $tester->run([
        'command' => 'scan',
        '--project' => $project,
        '--config' => $project.'/missing-config.php',
    ], ['capture_stderr_separately' => true]);

    expect($code)->toBe(ExitCode::Error->value)
        ->and($tester->getDisplay().$tester->getErrorOutput())->toContain('does not exist');

    removeTree($project);
});

it('reads PSR-4 roots and skips the entries that are not paths', function (): void {
    $project = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":["src",false],"Domain\\\\":"domain"}}}',
        'src/Fine.php' => "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n",
    ]);

    $tester = plumbingTester();

    $code = $tester->run([
        'command' => 'scan',
        '--project' => $project,
        '--format' => 'json',
    ], ['capture_stderr_separately' => true]);

    /** @var array{summary: array{files: int}} $report */
    $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(ExitCode::Success->value)
        ->and($report['summary']['files'])->toBe(1);

    removeTree($project);
});

it('registers nothing but views outside the console', function (): void {
    $application = new class(dirname(__DIR__, 3)) extends Application
    {
        public function runningInConsole(): bool
        {
            return false;
        }
    };

    $application->instance('config', new Illuminate\Config\Repository);

    $provider = new SloppyServiceProvider($application);
    $provider->register();
    $provider->boot();

    expect($application->bound(Heyosseus\Sloppy\Sloppy::class))->toBeTrue();
});
