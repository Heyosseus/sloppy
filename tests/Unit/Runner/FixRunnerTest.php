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
        // A project that says how it is formatted, so Pint is allowed to run.
        'pint.json' => '{"preset":"psr12"}',
        ...$files,
    ]);

    // The binaries `sloppy fix` looks for, so the detector finds them without
    // this test installing anything.
    mkdir($root.'/vendor/bin', 0o777, true);
    file_put_contents($root.'/vendor/bin/rector', '#!/usr/bin/env php');
    file_put_contents($root.'/vendor/bin/pint', '#!/usr/bin/env php');

    return [new Sloppy(Configuration::fromArray([...['paths' => ['src']], ...$config], $root)), $root];
}

/**
 * A tool runner whose Rector really rewrites the given files (outside a dry
 * run), so the runner can tell which files changed.
 *
 * @param  list<string>  $rewrites
 * @param  array<string, int>  $exitCodes
 */
function rewritingTools(array $rewrites = ['src/Reporting.php'], array $exitCodes = [], string $output = 'done'): RecordingToolRunner
{
    return new RecordingToolRunner($exitCodes, $output, static function (string $tool, array $command, string $cwd) use ($rewrites): void {
        if ($tool !== 'rector' || in_array('--dry-run', $command, true)) {
            return;
        }

        foreach ($rewrites as $path) {
            file_put_contents($cwd.'/'.$path, "\n// rewritten\n", FILE_APPEND);
        }
    });
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
    $tools = rewritingTools();

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
        // Nothing was rewritten, so there is nothing for Pint to format: run
        // over the untouched files it would only report unrelated style.
        ->and($tools->tools())->toBe(['rector'])
        ->and($output->messages())->toContain('info: Dry run: Pint was not run, because it formats only the files a real run rewrites.')
        ->and(is_file($root.'/rector-sloppy.php'))->toBeTrue()
        ->and($output->messages())->toContain('info: Dry run: no source file was changed; only the Rector configuration was written.');

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
        // Nothing was rewritten, so Pint's absence does not matter yet.
        ->and($output->messages())->not->toContain('warn: Pint is not installed, so the rewritten files were left unformatted.');

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

/**
 * A comment that says what the line below it already says -- SL109, which
 * `sloppy fix` removes itself.
 */
const RESTATING_COMMENT = <<<'PHP'
<?php

namespace App;

class Guard
{
    public function check(?User $user): bool
    {
        // Check if the user exists
        if ($user) {
            return true;
        }

        return false;
    }
}
PHP;

it('removes the comments that only restate their code, and formats what it touched', function (): void {
    [$sloppy, $root] = fixProject(['src/Guard.php' => RESTATING_COMMENT]);
    $output = new RecordingRunnerOutput;
    $tools = new RecordingToolRunner;

    $code = (new FixRunner($tools))->run($sloppy, new FixOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and(file_get_contents($root.'/src/Guard.php'))->not->toContain('// Check if the user exists')
        ->and($output->messages())->toContain('notice: Removed 1 comment(s) that only restated their code, in 1 file(s).')
        ->and($tools->tools())->toBe(['pint'])
        ->and($tools->commandFor('pint'))->toContain('src/Guard.php')
        ->and(implode("\n", $output->messages()))->toContain('info: Findings 1 -> 0.')
        ->and(implode("\n", $output->messages()))->not->toContain('need a person');

    removeTree($root);
});

it('says which comments it would remove on a dry run, and removes none', function (): void {
    [$sloppy, $root] = fixProject(['src/Guard.php' => RESTATING_COMMENT]);
    $output = new RecordingRunnerOutput;

    (new FixRunner(new RecordingToolRunner))->run($sloppy, new FixOptions(dryRun: true), $output);

    expect(file_get_contents($root.'/src/Guard.php'))->toBe(RESTATING_COMMENT)
        ->and($output->messages())->toContain('notice: Would remove 1 comment(s) that only restated their code, in 1 file(s).')
        ->and($output->messages())->toContain('info: Dry run: no source file was changed; only the Rector configuration was written.');

    removeTree($root);
});

it('keeps the comments when told to', function (): void {
    [$sloppy, $root] = fixProject(['src/Guard.php' => RESTATING_COMMENT]);
    $output = new RecordingRunnerOutput;
    $tools = new RecordingToolRunner;

    (new FixRunner($tools))->run($sloppy, new FixOptions(withComments: false), $output);

    expect(file_get_contents($root.'/src/Guard.php'))->toBe(RESTATING_COMMENT)
        ->and($output->messages())->toContain('warn: 1 finding(s) need a person: SL109 x1.')
        ->and($tools->calls())->toBe([]);

    removeTree($root);
});

it('removes the comments and runs Rector in the same pass', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD, 'src/Guard.php' => RESTATING_COMMENT]);
    $output = new RecordingRunnerOutput;
    $tools = rewritingTools();

    (new FixRunner($tools))->run($sloppy, new FixOptions, $output);

    expect($tools->tools())->toBe(['rector', 'pint'])
        ->and($tools->commandFor('pint'))->toContain('src/Guard.php')
        ->and($tools->commandFor('pint'))->toContain('src/Reporting.php')
        ->and(implode("\n", $output->messages()))->not->toContain('need a person');

    removeTree($root);
});

it('fails when the formatting pass after a comment removal fails', function (): void {
    [$sloppy, $root] = fixProject(['src/Guard.php' => RESTATING_COMMENT]);
    $output = new RecordingRunnerOutput;

    $code = (new FixRunner(new RecordingToolRunner(['pint' => 1])))->run($sloppy, new FixOptions, $output);

    expect($code)->toBe(ExitCode::Error);

    removeTree($root);
});

it('refuses to overwrite a Rector configuration it did not write', function (): void {
    [$sloppy, $root] = fixProject([
        'src/Reporting.php' => DEAD_PRIVATE_METHOD,
        'src/Guard.php' => RESTATING_COMMENT,
        'rector.php' => "<?php\n\n// The team's own Rector setup.\n",
    ]);
    $output = new RecordingRunnerOutput;
    $tools = new RecordingToolRunner;

    $code = (new FixRunner($tools))->run($sloppy, new FixOptions(configFile: 'rector.php'), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and(file_get_contents($root.'/rector.php'))->toBe("<?php\n\n// The team's own Rector setup.\n")
        ->and(implode("\n", $output->messages()))->toContain('error: rector.php already exists and was not generated by Sloppy')
        ->and($tools->calls())->toBe([])
        // Refused before anything was rewritten.
        ->and(file_get_contents($root.'/src/Guard.php'))->toBe(RESTATING_COMMENT);

    removeTree($root);
});

it('overwrites a configuration it generated itself on an earlier run', function (): void {
    [$sloppy, $root] = fixProject([
        'src/Reporting.php' => DEAD_PRIVATE_METHOD,
        'rector-sloppy.php' => "<?php\n\ndeclare(strict_types=1);\n\n// Generated by `sloppy --format=rector`. Run it with:\n",
    ]);

    $code = (new FixRunner(rewritingTools()))->run($sloppy, new FixOptions, new RecordingRunnerOutput);

    expect($code)->toBe(ExitCode::Success)
        ->and(is_file($root.'/rector-sloppy.php'))->toBeFalse();

    removeTree($root);
});

it('leaves the configuration in place for review when Rector is skipped', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    $output = new RecordingRunnerOutput;
    $tools = new RecordingToolRunner;

    $code = (new FixRunner($tools))->run($sloppy, new FixOptions(withRector: false), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and(is_file($root.'/rector-sloppy.php'))->toBeTrue()
        ->and($tools->calls())->toBe([])
        ->and($output->messages())->toContain('notice: Rector was skipped; rector-sloppy.php is left in place for review.');

    removeTree($root);
});

it('treats changes Rector found on a dry run as the answer, not a failure', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    $output = new RecordingRunnerOutput;
    $diff = "1 file with changes\n-    private function unusedHelper(): string\n [OK] 1 file would have been changed (dry-run) by Rector";

    $code = (new FixRunner(new RecordingToolRunner(['rector' => 2], $diff)))->run($sloppy, new FixOptions(dryRun: true), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->messages())->toContain('line: '.$diff)
        ->and($output->messages())->toContain('notice: rector would change the files above.')
        ->and(implode("\n", $output->messages()))->not->toContain('error:')
        ->and(file_get_contents($root.'/src/Reporting.php'))->toBe(DEAD_PRIVATE_METHOD);

    removeTree($root);
});

it('still fails a dry run when Rector itself failed', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    $output = new RecordingRunnerOutput;

    $code = (new FixRunner(new RecordingToolRunner(['rector' => 1], 'Could not parse')))->run($sloppy, new FixOptions(dryRun: true), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and(implode("\n", $output->messages()))->toContain('error: rector exited with 1:');

    removeTree($root);
});

it('formats only the files Rector actually rewrote', function (): void {
    $other = str_replace('class Reporting', 'class Exporting', DEAD_PRIVATE_METHOD);
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD, 'src/Exporting.php' => $other]);
    $tools = rewritingTools(['src/Reporting.php']);

    (new FixRunner($tools))->run($sloppy, new FixOptions, new RecordingRunnerOutput);

    expect($tools->commandFor('pint'))->toContain('src/Reporting.php')
        ->and($tools->commandFor('pint'))->not->toContain('src/Exporting.php');

    removeTree($root);
});

it('does not run Pint when Rector rewrote nothing', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    $tools = new RecordingToolRunner;

    (new FixRunner($tools))->run($sloppy, new FixOptions, new RecordingRunnerOutput);

    expect($tools->tools())->toBe(['rector']);

    removeTree($root);
});

it('leaves a project that is not Laravel and has no pint.json unformatted', function (): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    unlink($root.'/pint.json');
    $output = new RecordingRunnerOutput;
    $tools = rewritingTools();

    $code = (new FixRunner($tools))->run($sloppy, new FixOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($tools->tools())->toBe(['rector'])
        ->and(implode("\n", $output->messages()))->toContain('Pint was skipped: the project has no pint.json');

    removeTree($root);
});

it('formats a Laravel project without a pint.json in Laravel style', function (string $marker, string $content): void {
    [$sloppy, $root] = fixProject(['src/Reporting.php' => DEAD_PRIVATE_METHOD]);
    unlink($root.'/pint.json');
    file_put_contents($root.'/'.$marker, $content);
    $tools = rewritingTools();

    (new FixRunner($tools))->run($sloppy, new FixOptions, new RecordingRunnerOutput);

    expect($tools->tools())->toBe(['rector', 'pint']);

    removeTree($root);
})->with([
    'artisan' => ['artisan', "#!/usr/bin/env php\n"],
    'laravel/framework' => ['composer.json', '{"require":{"laravel/framework":"^12.0"},"autoload":{"psr-4":{"App\\\\":"src/"}}}'],
]);
