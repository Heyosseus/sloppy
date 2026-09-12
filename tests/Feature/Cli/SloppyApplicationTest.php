<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SloppyApplication;
use Heyosseus\Sloppy\Tests\Support\TempRepository;
use Symfony\Component\Console\Tester\ApplicationTester;

function tester(): ApplicationTester
{
    $application = new SloppyApplication('test');
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);

    return new ApplicationTester($application);
}

it('registers the three commands with scan as the default', function (): void {
    $application = new SloppyApplication('test');

    expect($application->has('scan'))->toBeTrue()
        ->and($application->has('diff'))->toBeTrue()
        ->and($application->has('baseline'))->toBeTrue();
});

it('scans a vanilla project and skips the laravel rules', function (): void {
    $project = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Fine.php' => "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n",
    ]);

    $tester = tester();
    $code = $tester->run([
        'command' => 'scan',
        '--project' => $project,
        '--format' => 'json',
    ], ['capture_stderr_separately' => true]);

    // Without the option above, the tester merges stdout and stderr, and the
    // project-root notice (Item 6) would sit ahead of the JSON. Decoding
    // getDisplay() alone -- stdout only -- is what proves it did not.
    /** @var array{rules_skipped: list<string>, findings: list<mixed>} $decoded */
    $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($decoded['findings'])->toBe([])
        ->and($decoded['rules_skipped'])->toHaveCount(10);

    removeTree($project);
});

it('finds real findings in vanilla php and fails on the threshold', function (): void {
    $project = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Bad.php' => godMethodSource(),
    ]);

    $tester = tester();
    $code = $tester->run([
        'command' => 'scan',
        '--project' => $project,
        '--format' => 'json',
        '--fail-on' => 'medium',
    ]);

    expect($code)->toBe(1)
        ->and($tester->getDisplay())->toContain('SL101');

    removeTree($project);
});

it('keeps the baseline notice out of the json report and on stderr instead', function (): void {
    $project = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Bad.php' => godMethodSource(),
    ]);

    tester()->run(['command' => 'baseline', '--project' => $project]);

    $tester = tester();
    $code = $tester->run([
        'command' => 'scan',
        '--project' => $project,
        '--format' => 'json',
    ], ['capture_stderr_separately' => true]);

    // Without the option above, the tester merges stdout and stderr, and the
    // baseline notice (Item 4) would sit ahead of the JSON. Decoding
    // getDisplay() alone -- stdout only -- is what proves it did not.
    /** @var array{findings: list<mixed>} $decoded */
    $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($decoded['findings'])->toBe([])
        ->and($tester->getErrorOutput())->toContain('1 existing finding(s) hidden by .sloppy-baseline.json.');

    removeTree($project);
});

it('records real findings into a baseline that a following scan then hides', function (): void {
    $project = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Bad.php' => godMethodSource(),
    ]);

    $baselineCode = tester()->run(['command' => 'baseline', '--project' => $project]);
    $baselinePath = $project.'/.sloppy-baseline.json';

    expect($baselineCode)->toBe(0)
        ->and(is_file($baselinePath))->toBeTrue();

    /** @var array{entries: list<array{rule: string, file: string, count: int}>} $baseline */
    $baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);

    // godMethodSource() is written to trip exactly one SL101 finding: content,
    // not just a non-empty baseline, is what proves `baseline` recorded the
    // right thing rather than merely something.
    expect($baseline['entries'])->toHaveCount(1)
        ->and($baseline['entries'][0])->toMatchArray([
            'rule' => 'SL101',
            'file' => 'src/Bad.php',
            'count' => 1,
        ]);

    $scanTester = tester();
    $scanCode = $scanTester->run([
        'command' => 'scan',
        '--project' => $project,
        '--format' => 'json',
    ], ['capture_stderr_separately' => true]);

    /** @var array{findings: list<mixed>} $decoded */
    $decoded = json_decode($scanTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

    expect($scanCode)->toBe(0)
        ->and($decoded['findings'])->toBe([]);

    removeTree($project);
});

it('reports a finding introduced in the working tree as new through the diff command', function (): void {
    if (! TempRepository::gitIsAvailable()) {
        $this->markTestSkipped('git is not available on this machine.');
    }

    $repository = TempRepository::create();
    $repository->write('app/Fine.php', "<?php\n\nclass Fine\n{\n}\n")->commit('first commit');
    // Uncommitted, so DiffRunner (working tree vs HEAD) sees it as new.
    $repository->write('app/Bad.php', godMethodSource());

    $tester = tester();
    $code = $tester->run([
        'command' => 'diff',
        '--project' => $repository->path,
        '--format' => 'json',
    ], ['capture_stderr_separately' => true]);

    /**
     * @var array{
     *     summary: array{new: int},
     *     new: list<array{rule: string, file: string}>,
     * } $decoded
     */
    $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(1)
        ->and($decoded['summary']['new'])->toBe(1)
        ->and($decoded['new'])->toHaveCount(1)
        ->and($decoded['new'][0])->toMatchArray(['rule' => 'SL101', 'file' => 'app/Bad.php']);

    $repository->remove();
});

it('exits 2 when the project cannot be located', function (): void {
    $tester = tester();
    $code = $tester->run([
        'command' => 'scan',
        '--project' => '/definitely/not/here',
    ]);

    expect($code)->toBe(2)
        ->and($tester->getDisplay())->toContain('does not exist');
});

it('rejects an unknown format with exit code 2', function (): void {
    $project = tempProject(['composer.json' => '{}']);

    $tester = tester();
    $code = $tester->run([
        'command' => 'scan',
        '--project' => $project,
        '--format' => 'xml',
    ]);

    expect($code)->toBe(2)
        ->and($tester->getDisplay())->toContain('Unknown --format [xml]');

    removeTree($project);
});
