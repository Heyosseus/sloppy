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
        ->toContain('sloppy:architecture')
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
        ->expectsOutputToContain('--top must be a number without a fraction, got "lots".')
        ->assertExitCode(ExitCode::Error->value);

    $this->artisan('sloppy:rules --format=emacs')
        ->expectsOutputToContain('Unknown ruleset format [emacs]')
        ->assertExitCode(ExitCode::Error->value);

    $this->artisan('sloppy:agents remove')
        ->expectsOutputToContain('Unknown action [remove]')
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

it('installs and uninstalls the agent hooks through artisan', function (): void {
    $root = artisanProject(['vendor/bin/sloppy' => '<?php', 'CLAUDE.md' => "# Team notes\n"]);

    $this->artisan('sloppy:agents install')
        ->expectsOutputToContain('Created .claude/settings.json')
        ->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy:agents uninstall')
        ->expectsOutputToContain('Deleted .claude/settings.json')
        ->assertExitCode(ExitCode::Success->value);

    expect(is_file($root.'/.claude/settings.json'))->toBeFalse()
        ->and(file_get_contents($root.'/CLAUDE.md'))->toBe("# Team notes\n");

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

it('shows and explains the architecture through artisan', function (): void {
    $root = artisanProject(['app/Http/Controllers/OrderController.php' => '<?php namespace App\Http\Controllers; class OrderController {}']);

    $this->artisan('sloppy:architecture')
        ->expectsOutputToContain('Architecture: preset laravel')
        ->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy:architecture OrderController --format=json')
        ->expectsOutputToContain('"role": "controller"')
        ->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy:architecture --format=sarif')
        ->expectsOutputToContain('writes console or json')
        ->assertExitCode(ExitCode::Error->value);

    removeTree($root);
});

it('draws the graph, places a class and writes the prompt through artisan', function (): void {
    $root = artisanProject(['app/Http/Controllers/OrderController.php' => '<?php namespace App\Http\Controllers; class OrderController {}']);

    $this->artisan('sloppy:architecture graph')
        ->expectsOutputToContain('flowchart LR')
        ->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy:architecture place a controller for orders --name=Refund --format=json')
        ->expectsOutputToContain('"directory": "app/Http/Controllers"')
        ->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy:architecture prompt')
        ->expectsOutputToContain('# Describe this project\'s architecture for Sloppy')
        ->assertExitCode(ExitCode::Success->value);

    $this->artisan('sloppy:architecture init --write --force')
        ->assertExitCode(ExitCode::Success->value);

    expect(is_file($root.'/sloppy-architecture.php'))->toBeTrue();

    removeTree($root);
});

it('offers the same options on Artisan as on the standalone binary', function (string $standalone, string $artisan): void {
    // The standalone binary has to be told where the project is; Artisan
    // already knows. Everything else should match.
    $framework = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'silent', 'env'];
    $names = static fn (Symfony\Component\Console\Command\Command $command): array => array_values(array_diff(
        array_keys($command->getDefinition()->getOptions()),
        $framework,
    ));

    $cli = array_values(array_diff($names((new Heyosseus\Sloppy\Cli\SloppyApplication('test'))->find($standalone)), $standalone === 'mcp' ? [] : ['project', 'config']));
    $laravel = $names(app(Illuminate\Contracts\Console\Kernel::class)->all()[$artisan]);

    sort($cli);
    sort($laravel);

    expect($laravel)->toBe($cli);
})->with([
    ['scan', 'sloppy'],
    ['diff', 'sloppy:diff'],
    ['review', 'sloppy:review'],
    ['baseline', 'sloppy:baseline'],
    ['ci', 'sloppy:ci'],
    ['fix', 'sloppy:fix'],
    ['health', 'sloppy:health'],
    ['watch', 'sloppy:watch'],
    ['rules', 'sloppy:rules'],
    ['architecture', 'sloppy:architecture'],
    ['agents', 'sloppy:agents'],
    ['mcp', 'sloppy:mcp'],
]);

it('narrows the architecture to the given paths through artisan too', function (): void {
    $root = artisanProject([GOD_METHOD_APP => godMethodSource(), 'other/Thing.php' => "<?php\n\nnamespace Other;\n\nclass Thing\n{\n}\n"]);

    $this->artisan('sloppy:architecture --path=other --format=json')
        ->doesntExpectOutputToContain('Bad')
        ->assertExitCode(ExitCode::Success->value);

    removeTree($root);
});
