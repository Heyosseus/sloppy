<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Console\Commands\SloppyMcpCommand;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Point the package at a throwaway project and re-bind the container, the way
 * the other Artisan tests do.
 *
 * @param  array<string, string>  $files
 * @param  array<string, mixed>  $config
 */
function artisanProject(array $files, array $config = []): string
{
    $root = tempProject($files);

    /** @var Repository $repository */
    $repository = app(Repository::class);

    /** @var array<string, mixed> $current */
    $current = $repository->get('sloppy', []);

    $merged = [...$current, ...$config];
    $repository->set('sloppy', $merged);

    app()->instance(Configuration::class, Configuration::fromArray($merged, $root));
    app()->instance(Sloppy::class, new Sloppy(Configuration::fromArray($merged, $root)));

    return $root;
}

const GOD_METHOD_APP = 'app/Bad.php';

it('registers every command', function (): void {
    $commands = array_keys(app(Illuminate\Contracts\Console\Kernel::class)->all());

    expect($commands)->toContain('sloppy')
        ->toContain('sloppy:diff')
        ->toContain('sloppy:review')
        ->toContain('sloppy:baseline')
        ->toContain('sloppy:ci')
        ->toContain('sloppy:fix')
        ->toContain('sloppy:health')
        ->toContain('sloppy:rules')
        ->toContain('sloppy:agents')
        ->toContain('sloppy:mcp')
        ->toContain('sloppy:help');
});

it('runs a CI report through artisan', function (): void {
    $root = artisanProject([GOD_METHOD_APP => godMethodSource()], ['fail_on' => 'high']);

    $this->artisan('sloppy:ci --format=github')
        ->expectsOutputToContain('::error file=app/Bad.php')
        ->assertExitCode(ExitCode::FindingsAboveThreshold->value);

    removeTree($root);
});

it('writes a CI report to a file through artisan', function (): void {
    $root = artisanProject([GOD_METHOD_APP => godMethodSource()], ['fail_on' => 'never']);

    $this->artisan('sloppy:ci --format=gitlab --report=quality.json --no-summary')
        ->assertExitCode(ExitCode::Success->value);

    expect(file_get_contents($root.'/quality.json'))->toContain('"check_name": "SL101"');

    removeTree($root);
});

it('reports a bad option through artisan rather than running', function (): void {
    $root = artisanProject([GOD_METHOD_APP => godMethodSource()]);

    $this->artisan('sloppy:ci --format=xml')
        ->expectsOutputToContain('Unknown --format [xml]')
        ->assertExitCode(ExitCode::Error->value);

    $this->artisan('sloppy:health --top=lots')
        ->expectsOutputToContain('--top must be a number.')
        ->assertExitCode(ExitCode::Error->value);

    $this->artisan('sloppy:rules --format=emacs')
        ->expectsOutputToContain('Unknown ruleset format [emacs]')
        ->assertExitCode(ExitCode::Error->value);

    $this->artisan('sloppy:agents uninstall')
        ->expectsOutputToContain('Unknown action [uninstall]')
        ->assertExitCode(ExitCode::Error->value);

    removeTree($root);
});

it('reports the project health through artisan', function (): void {
    $root = artisanProject([GOD_METHOD_APP => godMethodSource()]);

    $this->artisan('sloppy:health')
        ->expectsOutputToContain('/100')
        ->expectsOutputToContain('SL101 God Method')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('writes agent rules through artisan', function (): void {
    $root = artisanProject([GOD_METHOD_APP => godMethodSource()]);

    $this->artisan('sloppy:rules --format=agents')
        ->expectsOutputToContain('AGENTS.md')
        ->assertExitCode(ExitCode::Success->value);

    expect(file_get_contents($root.'/AGENTS.md'))->toContain('### SL101 God Method');

    removeTree($root);
});

it('generates a fix plan through artisan', function (): void {
    $root = artisanProject([GOD_METHOD_APP => godMethodSource()]);

    $this->artisan('sloppy:fix --dry-run')
        ->expectsOutputToContain('1 finding(s) need a person: SL101 x1.')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});

it('serves the MCP protocol through artisan', function (): void {
    $root = artisanProject([
        GOD_METHOD_APP => godMethodSource(),
        'in.jsonl' => '{"jsonrpc":"2.0","id":7,"method":"tools/list"}'."\n",
        'out.jsonl' => '',
    ]);

    // The streams are injected rather than taken from the terminal: a test
    // that read the real standard input would wait for a keystroke that is
    // never coming.
    $command = new SloppyMcpCommand(
        new SplFileObject($root.'/in.jsonl'),
        new SplFileObject($root.'/out.jsonl', 'w'),
    );

    $command->setLaravel(app());

    $code = $command->run(
        new ArrayInput(['--project' => $root], $command->getDefinition()),
        new BufferedOutput,
    );

    expect($code)->toBe(ExitCode::Success->value)
        ->and(file_get_contents($root.'/out.jsonl'))->toContain('sloppy_scan');

    // Windows will not delete a file whose handle is still open.
    unset($command);
    gc_collect_cycles();

    removeTree($root);
});

it('installs the agent hooks through artisan', function (): void {
    $root = artisanProject([GOD_METHOD_APP => godMethodSource(), 'vendor/bin/sloppy' => '<?php']);

    $this->artisan('sloppy:agents --dry-run')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy:agents')
        ->expectsOutputToContain('.claude/settings.json')
        ->assertExitCode(ExitCode::Success->value);

    expect(file_get_contents($root.'/.claude/settings.json'))->toContain('vendor/bin/sloppy\" hook post-edit');

    removeTree($root);
});

it('explains every command through artisan', function (): void {
    $this->artisan('sloppy:help')
        ->expectsOutputToContain('In a pipeline')
        ->expectsOutputToContain('php artisan sloppy:rules')
        ->assertExitCode(ExitCode::Success->value);
});
