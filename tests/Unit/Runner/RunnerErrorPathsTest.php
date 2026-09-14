<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Ci\CiEnvironment;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\BaselineOptions;
use Heyosseus\Sloppy\Runner\BaselineRunner;
use Heyosseus\Sloppy\Runner\CiOptions;
use Heyosseus\Sloppy\Runner\CiRunner;
use Heyosseus\Sloppy\Runner\DiffOptions;
use Heyosseus\Sloppy\Runner\DiffRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\FixOptions;
use Heyosseus\Sloppy\Runner\FixRunner;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;
use Heyosseus\Sloppy\Tests\Support\RecordingToolRunner;
use Heyosseus\Sloppy\Tests\Support\TempRepository;

/**
 * A class with a private method nothing calls: SL105, which Rector fixes.
 */
const UNUSED_PRIVATE_METHOD = <<<'PHP'
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
 * A project whose rule registry cannot be built, so anything that reaches for
 * a rule fails the way a misconfigured project really does.
 *
 * @return array{0: Sloppy, 1: string}
 */
function brokenRulesProject(string $root): array
{
    return [new Sloppy(Configuration::fromArray([
        'paths' => ['src'],
        'custom_rules' => ['App\\NotARule'],
    ], $root)), $root];
}

it('reports a bad option instead of writing a baseline', function (): void {
    $root = tempProject(['composer.json' => '{}']);
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray([], $root));

    $code = (new BaselineRunner)->run($sloppy, new BaselineOptions(minConfidence: 900), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toContain('--min-confidence must be a number between 0 and 100.');

    removeTree($root);
});

it('reports a baseline it could not write', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Bad.php' => godMethodSource(),
        'occupied' => 'a file where the baseline directory should be',
    ]);

    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray([
        'paths' => ['src'],
        'baseline' => 'occupied/baseline.json',
    ], $root));

    $code = (new BaselineRunner)->run($sloppy, new BaselineOptions(force: true), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and(implode("\n", $output->messages()))->toContain('could not be created');

    removeTree($root);
});

it('reports a bad option instead of diffing', function (): void {
    $root = tempProject(['composer.json' => '{}']);
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray([], $root));

    $code = (new DiffRunner)->run($sloppy, new DiffOptions(minConfidence: -1), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toContain('--min-confidence must be a number between 0 and 100.');

    removeTree($root);
});

it('reports an analysis that failed mid-diff', function (): void {
    $repository = TempRepository::create()
        ->write('composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}')
        ->write('src/Fine.php', "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n")
        ->commit('first');

    [$sloppy] = brokenRulesProject($repository->path);
    $output = new RecordingRunnerOutput;

    $code = (new DiffRunner)->run($sloppy, new DiffOptions(base: 'HEAD'), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and(implode("\n", $output->messages()))->toContain('must implement');

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('fails the CI step when the diff report cannot be written', function (): void {
    $repository = TempRepository::create()
        ->write('composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}')
        ->write('src/Fine.php', "<?php\n\nnamespace App;\n\nclass Fine\n{\n}\n")
        ->commit('first');

    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['src'], 'fail_on' => 'never'], $repository->path));
    $output = new RecordingRunnerOutput;

    $code = (new CiRunner(new CiEnvironment))->run(
        $sloppy,
        new CiOptions(base: 'HEAD', report: 'missing-directory/report.json'),
        $output,
    );

    expect($code)->toBe(ExitCode::Error)
        ->and(implode("\n", $output->messages()))->toContain('Could not write the report to');

    $repository->remove();
})->skip(fn (): bool => ! TempRepository::gitIsAvailable(), 'git is not available.');

it('counts a failing formatter as a failure of the fix run', function (): void {
    $root = tempProject([
        'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
        'src/Reporting.php' => UNUSED_PRIVATE_METHOD,
        'vendor/bin/pint' => '#!/usr/bin/env php',
        'vendor/bin/rector' => '#!/usr/bin/env php',
    ]);

    $sloppy = new Sloppy(Configuration::fromArray(['paths' => ['src']], $root));
    $output = new RecordingRunnerOutput;
    $tools = new RecordingToolRunner(['pint' => 2], 'pint could not parse the file');

    $code = (new FixRunner($tools))->run($sloppy, new FixOptions, $output);

    expect($code)->toBe(ExitCode::Error)
        ->and(implode("\n", $output->messages()))->toContain('pint exited with 2:');

    removeTree($root);
});
