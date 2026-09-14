<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\RulesetFile;
use Heyosseus\Sloppy\Agent\RulesetFormat;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\RulesOptions;
use Heyosseus\Sloppy\Runner\RulesRunner;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

/**
 * @param  array<string, string>  $files
 * @param  array<string, mixed>  $config
 * @return array{0: Sloppy, 1: string}
 */
function rulesProject(array $files = [], array $config = []): array
{
    $root = tempProject(['composer.json' => '{"require":{"laravel/framework":"^12.0"}}', ...$files]);

    return [new Sloppy(Configuration::fromArray($config, $root)), $root];
}

it('writes CLAUDE.md by default', function (): void {
    [$sloppy, $root] = rulesProject();
    $output = new RecordingRunnerOutput;

    $code = (new RulesRunner)->run($sloppy, new RulesOptions, $output);
    $written = (string) file_get_contents($root.'/CLAUDE.md');

    expect($code)->toBe(ExitCode::Success)
        ->and($written)->toContain(RulesetFile::BEGIN)
        ->and($written)->toContain('### SL101 God Method')
        ->and($output->messages())->toContain('info: Created CLAUDE.md for Claude Code (24 rule(s)).');

    removeTree($root);
});

it('writes one file per format asked for, creating directories as needed', function (): void {
    [$sloppy, $root] = rulesProject();
    $output = new RecordingRunnerOutput;

    $code = (new RulesRunner)->run($sloppy, new RulesOptions(formats: [
        RulesetFormat::Cursor,
        RulesetFormat::Copilot,
        RulesetFormat::Json,
    ]), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and(is_file($root.'/.cursorrules'))->toBeTrue()
        ->and(is_file($root.'/.github/copilot-instructions.md'))->toBeTrue()
        ->and(is_file($root.'/sloppy-rules.json'))->toBeTrue()
        ->and(json_decode((string) file_get_contents($root.'/sloppy-rules.json'), true))->toBeArray();

    removeTree($root);
});

it('keeps what a team already wrote in CLAUDE.md and refreshes only its own block', function (): void {
    [$sloppy, $root] = rulesProject(['CLAUDE.md' => "# Notes\n\nDeploy with `make ship`.\n"]);
    $first = new RecordingRunnerOutput;
    $second = new RecordingRunnerOutput;

    (new RulesRunner)->run($sloppy, new RulesOptions, $first);
    (new RulesRunner)->run($sloppy, new RulesOptions, $second);

    $written = (string) file_get_contents($root.'/CLAUDE.md');

    expect($written)->toContain('Deploy with `make ship`.')
        ->and(mb_substr_count($written, RulesetFile::BEGIN))->toBe(1)
        ->and($first->messages())->toContain('info: Updated CLAUDE.md for Claude Code (24 rule(s)).')
        ->and($second->messages())->toContain('info: Refreshed the Sloppy block in CLAUDE.md for Claude Code (24 rule(s)).');

    removeTree($root);
});

it('refuses to overwrite a generated JSON file without --force', function (): void {
    [$sloppy, $root] = rulesProject(['sloppy-rules.json' => '{"mine":true}']);
    $output = new RecordingRunnerOutput;

    $code = (new RulesRunner)->run($sloppy, new RulesOptions(formats: [RulesetFormat::Json]), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages())->toContain('error: sloppy-rules.json already exists. Pass --force to overwrite it.')
        ->and(file_get_contents($root.'/sloppy-rules.json'))->toBe('{"mine":true}');

    $forced = new RecordingRunnerOutput;
    $code = (new RulesRunner)->run($sloppy, new RulesOptions(formats: [RulesetFormat::Json], force: true), $forced);

    expect($code)->toBe(ExitCode::Success)
        ->and(file_get_contents($root.'/sloppy-rules.json'))->not->toBe('{"mine":true}');

    removeTree($root);
});

it('writes where it was told to instead', function (): void {
    [$sloppy, $root] = rulesProject();
    $output = new RecordingRunnerOutput;

    $code = (new RulesRunner)->run($sloppy, new RulesOptions(output: 'docs/agents.md'), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and(is_file($root.'/docs/agents.md'))->toBeTrue()
        ->and(is_file($root.'/CLAUDE.md'))->toBeFalse();

    removeTree($root);
});

it('prints the ruleset instead of writing anything', function (): void {
    [$sloppy, $root] = rulesProject();
    $output = new RecordingRunnerOutput;

    $code = (new RulesRunner)->run($sloppy, new RulesOptions(formats: [RulesetFormat::Claude, RulesetFormat::Json], stdout: true), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and(is_file($root.'/CLAUDE.md'))->toBeFalse()
        ->and($output->reports())->toHaveCount(2)
        ->and($output->reports()[0][0])->toBe('markdown')
        ->and($output->reports()[1][0])->toBe('json');

    removeTree($root);
});

it('reports a file it could not write', function (): void {
    [$sloppy, $root] = rulesProject();
    $output = new RecordingRunnerOutput;

    // A directory where the file should go: the write fails, and saying so is
    // the whole of the error path.
    mkdir($root.'/CLAUDE.md');

    expect((new RulesRunner)->run($sloppy, new RulesOptions, $output))->toBe(ExitCode::Error)
        ->and($output->messages())->toContain('error: Could not write CLAUDE.md.');

    removeTree($root);
});

it('reports a directory it could not create', function (): void {
    [$sloppy, $root] = rulesProject(['docs' => 'this is a file, not a directory']);
    $output = new RecordingRunnerOutput;

    $code = (new RulesRunner)->run($sloppy, new RulesOptions(output: 'docs/agents.md'), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toContain('error: Could not create ');

    removeTree($root);
});

it('refuses to write a ruleset with no rules in it', function (): void {
    [$sloppy, $root] = rulesProject(config: ['framework' => 'none']);
    $output = new RecordingRunnerOutput;

    $narrowed = $sloppy->onlyRules(['SL999']);

    expect((new RulesRunner)->run($narrowed, new RulesOptions, $output))->toBe(ExitCode::Error)
        ->and($output->messages())->toContain('error: No rules are enabled, so there is nothing to write. Check sloppy.rules.');

    removeTree($root);
});
