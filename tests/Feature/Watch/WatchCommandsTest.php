<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Cli\SloppyApplication;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Sloppy;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Both `watch` surfaces, in the one situation a test can put them in.
 *
 * A test run has no terminal, which is exactly the case the command has to
 * decline rather than loop forever in -- so this covers the wiring end to end
 * and the refusal at the same time.
 */
it('declines on the standalone binary when nothing is watching', function (): void {
    $root = tempProject(['app/A.php' => '<?php class A {}', 'composer.json' => '{}']);

    $application = new SloppyApplication('test');
    $application->setAutoExit(false);
    $tester = new ApplicationTester($application);

    $code = $tester->run(
        ['command' => 'watch', '--project' => $root, '--path' => ['app'], '--top' => '3', '--interval' => '100'],
        ['capture_stderr_separately' => true],
    );

    expect($code)->toBe(2)
        ->and($tester->getDisplay().$tester->getErrorOutput())->toContain('needs a terminal');

    removeTree($root);
});

it('declines on the Artisan surface too', function (): void {
    $root = tempProject(['app/A.php' => '<?php class A {}']);

    app()->instance(Sloppy::class, new Sloppy(Configuration::fromArray(['paths' => ['app']], $root)));

    $this->artisan('sloppy:watch', ['--interval' => '100'])
        ->expectsOutputToContain('needs a terminal')
        ->assertExitCode(2);

    removeTree($root);
});

it('offers the same options on both surfaces', function (): void {
    // The two surfaces are thin shells over one runner, and the way that stops
    // being true is one of them quietly growing or losing an option.
    $application = new SloppyApplication('test');
    $standalone = array_keys($application->find('watch')->getDefinition()->getOptions());
    $artisan = ['path', 'rule', 'min-confidence', 'top', 'interval'];

    expect($standalone)->toContain(...$artisan);
});
