<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\FixOptions;
use Heyosseus\Sloppy\Runner\FixRunner;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;
use Heyosseus\Sloppy\Tests\Support\RecordingToolRunner;

/**
 * A class with a private method nothing calls -- SL105, which Rector fixes.
 */
const DEAD_PRIVATE_METHOD = <<<'PHP'
<?php

namespace App;

class Reporting
{
    public function run(): string
    {
        return 'ok';
    }

    private function unusedHelper(): string
    {
        $value = 'never called';

        return strtoupper($value);
    }
}
PHP;

/**
 * @param  array<string, string>  $files
 * @param  array<string, mixed>  $config
 * @return array{0: Sloppy, 1: string}
 */
function fixProject(array $files, array $config = []): array
{
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        ...$files,
    ]);

    // The binaries `sloppy fix` looks for, so the detector finds them without
    // this test installing anything.
    mkdir($root.'/vendor/bin', 0o777, true);
    file_put_contents($root.'/vendor/bin/rector', '#!/usr/bin/env php');
    file_put_contents($root.'/vendor/bin/pint', '#!/usr/bin/env php');

    return [new Sloppy(Configuration::fromArray([...['paths' => ['src']], ...$config], $root)), $root];
}

it('says nothing and succeeds when sloppy is disabled', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD], ['enabled' => false]);
    $output = new RecordingRunnerOutput;

    expect((new FixRunner)->run($sloppy, new FixOptions, $output))->toBe(ExitCode::Success)
        ->and($output->messages())->toBe(['info: Sloppy is disabled (sloppy.enabled is false).']);

    removeTree($root);
});

it('reports a bad option rather than running anything', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    $tools = new RecordingToolRunner;

    $code = (new FixRunner($tools))->run($sloppy, new FixOptions(minConfidence: -5), new RecordingRunnerOutput);

    expect($code)->toBe(ExitCode::Error)
        ->and($tools->calls())->toBe([]);

    removeTree($root);
});

it('says there is nothing to fix when the code is clean', function (): void {
    [$sloppy, $root] = fixProject(['src/Fine.php' => "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n"]);
    $output = new RecordingRunnerOutput;
    $tools = new RecordingToolRunner;

    expect((new FixRunner($tools))->run($sloppy, new FixOptions, $output))->toBe(ExitCode::Success)
        ->and($output->messages())->toBe(['info: Nothing to fix: no findings.'])
        ->and($tools->calls())->toBe([]);

    removeTree($root);
});

it('runs Rector over the generated config and Pint over the files it touched', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    $output = new RecordingRunnerOutput;
    $tools = new RecordingToolRunner;

    $code = (new FixRunner($tools))->run($sloppy, new FixOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($tools->tools())->toBe(['rector', 'pint'])
        ->and($tools->commandFor('rector'))->toContain('process')
        ->and($tools->commandFor('rector'))->toContain('--config=rector-sloppy.php')
        ->and($tools->commandFor('rector'))->not->toContain('--dry-run')
        ->and($tools->commandFor('pint'))->toContain('src/Reporting.php')
        ->and($tools->calls()[0]['cwd'])->toBe($root)
        ->and($output->messages())->toContain('notice: 1 of 1 finding(s) have an automated fix. Wrote rector-sloppy.php.')
        ->and($output->messages())->toContain('notice: rector finished.')
        ->and($output->messages())->toContain('notice: pint finished.')
        // The generated config is temporary unless the user asks to keep it.
        ->and(is_file($root.'/rector-sloppy.php'))->toBeFalse();

    removeTree($root);
});

it('keeps the configuration when asked, and passes the dry-run flags through', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    $output = new RecordingRunnerOutput;
    $tools = new RecordingToolRunner;

    $code = (new FixRunner($tools))->run($sloppy, new FixOptions(dryRun: true, keepConfig: true), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($tools->commandFor('rector'))->toContain('--dry-run')
        ->and($tools->commandFor('pint'))->toContain('--test')
        ->and(is_file($root.'/rector-sloppy.php'))->toBeTrue()
        ->and($output->messages())->toContain('info: Dry run: nothing was written.');

    removeTree($root);
});

it('skips the tools it was told to skip', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    $tools = new RecordingToolRunner;

    (new FixRunner($tools))->run($sloppy, new FixOptions(withRector: false, withPint: false), new RecordingRunnerOutput);

    expect($tools->calls())->toBe([]);

    removeTree($root);
});

it('reports a tool that failed, and fails with it', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    $output = new RecordingRunnerOutput;
    $tools = new RecordingToolRunner(['rector' => 1], "Something went wrong\nin a file\n");

    $code = (new FixRunner($tools))->run($sloppy, new FixOptions, $output);

    expect($code)->toBe(ExitCode::Error)
        ->and(implode("\n", $output->messages()))->toContain('error: rector exited with 1:')
        ->and(implode("\n", $output->messages()))->toContain('Something went wrong');

    removeTree($root);
});

it('says which tool is missing instead of failing', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Reporting.php' => DEAD_PRIVATE_METHOD,
    ]);

    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['src']], $root));
    $output = new RecordingRunnerOutput;

    $code = (new FixRunner(new RecordingToolRunner))->run($sloppy, new FixOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->messages())->toContain('warn: Rector is not installed. Run composer require --dev rector/rector, then run the generated config.')
        ->and($output->messages())->toContain('warn: Pint is not installed, so the rewritten files were left unformatted.');

    removeTree($root);
});

it('names what is left for a person when nothing can be automated', function (): void {
    [$sloppy, $root] = fixProject(['src/Bad.php' => godMethodSource()]);
    $output = new RecordingRunnerOutput;
    $tools = new RecordingToolRunner;

    $code = (new FixRunner($tools))->run($sloppy, new FixOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->messages())->toContain('warn: 1 finding(s) need a person: SL101 x1.')
        ->and($tools->calls())->toBe([]);

    removeTree($root);
});

it('reports the score it moved', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    $output = new RecordingRunnerOutput;

    (new FixRunner(new RecordingToolRunner))->run($sloppy, new FixOptions, $output);

    expect(implode("\n", $output->messages()))->toContain('info: Findings 1 -> 1. Score ');

    removeTree($root);
});

it('reports a configuration it could not write', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    $output = new RecordingRunnerOutput;

    $code = (new FixRunner(new RecordingToolRunner))->run(
        $sloppy,
        new FixOptions(configFile: 'missing-directory/rector.php'),
        $output,
    );

    expect($code)->toBe(ExitCode::Error)
        ->and(implode("\n", $output->messages()))->toContain('error: Could not write the Rector configuration to');

    removeTree($root);
});
