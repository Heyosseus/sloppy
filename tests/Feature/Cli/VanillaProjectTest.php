<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SloppyApplication;
use Symfony\Component\Console\Tester\ApplicationTester;

it('analyses a framework-free project through the binary', function (): void {
    $application = new SloppyApplication('test');
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);

    $code = $tester->run([
        'command' => 'scan',
        '--project' => dirname(__DIR__, 2).'/Fixtures/Vanilla',
        '--format' => 'json',
        '--fail-on' => 'never',
    ]);

    /**
     * @var array{
     *     findings: list<array{rule: string}>,
     *     rules_skipped: list<string>,
     *     summary: array{files: int},
     * } $decoded
     */
    $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    $rules = array_map(static fn (array $finding): string => $finding['rule'], $decoded['findings']);

    expect($code)->toBe(0)
        // Framework-independent rules still run.
        ->and($rules)->toContain('SL101')
        ->and($rules)->toContain('SL107')
        // Laravel rules are absent, and said so.
        ->and($rules)->not->toContain('SL203')
        ->and($decoded['rules_skipped'])->toHaveCount(10)
        // PSR-4 discovery found src/ with no config file present.
        ->and($decoded['summary']['files'])->toBe(1);
});

it('names the skipped rules in console output too', function (): void {
    $application = new SloppyApplication('test');
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);

    $tester->run([
        'command' => 'scan',
        '--project' => dirname(__DIR__, 2).'/Fixtures/Vanilla',
        '--fail-on' => 'never',
    ]);

    expect($tester->getDisplay())->toContain('10 rule(s) skipped');
});
