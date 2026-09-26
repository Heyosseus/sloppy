<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Runner\AgentsOptions;
use Heyosseus\Sloppy\Runner\AgentsRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;
use Heyosseus\Sloppy\Tests\Support\RecordingRunnerOutput;

function agentsSloppy(string $project): Sloppy
{
    return new Sloppy(Configuration::fromArray([], $project));
}

it('wires Claude Code up and writes the ruleset beside it', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php']);
    $output = new RecordingRunnerOutput;

    $code = (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(binary: '/elsewhere/sloppy'), $output);

    $settings = (string) file_get_contents($project.'/.claude/settings.json');

    expect($code)->toBe(ExitCode::Success)
        ->and($settings)->toContain('php \"$CLAUDE_PROJECT_DIR/vendor/bin/sloppy\" hook post-edit')
        ->and($settings)->toContain('hook stop')
        ->and(is_file($project.'/CLAUDE.md'))->toBeTrue()
        ->and($output->messages()[0])->toBe('info: Created .claude/settings.json: Claude Code now runs Sloppy after every edit, and checks the whole change before it finishes.')
        ->and(implode("\n", $output->messages()))->toContain('MCP');

    removeTree($project);
});

it('says it updated a settings file that was already there', function (): void {
    $project = tempProject(['.claude/settings.json' => '{"model": "opus"}', '.mcp.json' => '{"mcpServers": {"sloppy": {}}}']);
    $output = new RecordingRunnerOutput;

    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(binary: '/tools/sloppy.phar'), $output);

    expect($output->messages()[0])->toStartWith('info: Updated .claude/settings.json')
        ->and((string) file_get_contents($project.'/.claude/settings.json'))->toContain('"model": "opus"')
        // Already registered, so no nudge about the MCP server.
        ->and(implode("\n", $output->messages()))->not->toContain('MCP');

    removeTree($project);
});

it('writes personal settings when asked, leaving the shared file alone', function (): void {
    $project = tempProject(['composer.json' => '{}']);

    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(local: true, binary: '/tools/sloppy.phar'), new RecordingRunnerOutput);

    expect(is_file($project.'/.claude/settings.local.json'))->toBeTrue()
        ->and(is_file($project.'/.claude/settings.json'))->toBeFalse();

    removeTree($project);
});

it('prints what it would write and writes nothing on a dry run', function (): void {
    $project = tempProject(['composer.json' => '{}']);
    $output = new RecordingRunnerOutput;

    $code = (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(dryRun: true, binary: '/tools/sloppy.phar'), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->reportBody())->toContain('php \"/tools/sloppy.phar\" hook stop')
        ->and($output->messages())->toBe(['info: Dry run: .claude/settings.json and CLAUDE.md were not written.'])
        ->and(is_dir($project.'/.claude'))->toBeFalse()
        ->and(is_file($project.'/CLAUDE.md'))->toBeFalse();

    removeTree($project);
});

it('leaves a settings file it cannot read exactly as it was', function (): void {
    $project = tempProject(['.claude/settings.json' => '{"hooks": ']);
    $output = new RecordingRunnerOutput;

    $code = (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(binary: '/tools/sloppy.phar'), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toStartWith('error: .claude/settings.json was left alone. It is not valid JSON')
        ->and((string) file_get_contents($project.'/.claude/settings.json'))->toBe('{"hooks": ');

    removeTree($project);
});

it('reports a settings directory it cannot create', function (): void {
    // A file where the directory should be.
    $project = tempProject(['.claude' => 'not a directory']);
    $output = new RecordingRunnerOutput;

    $code = (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(binary: '/tools/sloppy.phar'), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toStartWith('error: Could not write .claude/settings.json');

    removeTree($project);
});

it('fails when the ruleset cannot be written, after installing the hooks', function (): void {
    $project = tempProject(['composer.json' => '{}']);
    $output = new RecordingRunnerOutput;
    $sloppy = new Sloppy(Configuration::fromArray(['rules' => array_fill_keys(
        agentsSloppy($project)->rules()->ids(),
        ['enabled' => false],
    )], $project));

    $code = (new AgentsRunner)->run($sloppy, new AgentsOptions(binary: '/tools/sloppy.phar'), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and(is_file($project.'/.claude/settings.json'))->toBeTrue();

    removeTree($project);
});
