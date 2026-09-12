<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SloppyApplication;
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
    ]);

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
