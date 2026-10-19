<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\AgentHost;
use Heyosseus\Sloppy\Agent\RulesetGenerator;
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
        ->and($settings)->toContain('php \"${CLAUDE_PROJECT_DIR}/vendor/bin/sloppy\" hook post-edit')
        ->and($settings)->toContain('hook stop')
        ->and(is_file($project.'/CLAUDE.md'))->toBeTrue()
        ->and($output->messages()[0])->toBe('info: Created .claude/settings.json: Claude Code now runs Sloppy after every edit, and checks the whole change before it finishes.')
        ->and(implode("\n", $output->messages()))->toContain('MCP');

    removeTree($project);
});

it('says it updated a settings file that was already there', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php', '.claude/settings.json' => '{"model": "opus"}', '.mcp.json' => '{"mcpServers": {"sloppy": {}}}']);
    $output = new RecordingRunnerOutput;

    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(binary: '/tools/sloppy.phar'), $output);

    expect($output->messages()[0])->toStartWith('info: Updated .claude/settings.json')
        ->and((string) file_get_contents($project.'/.claude/settings.json'))->toContain('"model": "opus"')
        // Already registered, so no nudge about the MCP server.
        ->and(implode("\n", $output->messages()))->not->toContain('MCP');

    removeTree($project);
});

it('writes personal settings and rules when asked, leaving the shared files alone', function (): void {
    $project = tempProject(['composer.json' => '{}', 'vendor/bin/sloppy' => '<?php', 'CLAUDE.md' => "# Team notes\n"]);

    $code = (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(local: true, binary: '/tools/sloppy.phar'), new RecordingRunnerOutput);

    expect($code)->toBe(ExitCode::Success)
        ->and(is_file($project.'/.claude/settings.local.json'))->toBeTrue()
        ->and(is_file($project.'/.claude/settings.json'))->toBeFalse()
        // The committed CLAUDE.md is the team's; the rules go in the personal one.
        ->and(file_get_contents($project.'/CLAUDE.md'))->toBe("# Team notes\n")
        ->and((string) file_get_contents($project.'/CLAUDE.local.md'))->toContain('### SL101 God Method');

    removeTree($project);
});

it('keeps a hook that names a path on this machine out of the shared settings', function (): void {
    $project = tempProject(['composer.json' => '{}']);
    $output = new RecordingRunnerOutput;

    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(binary: 'C:\\tools\\sloppy.phar'), $output);

    expect(is_file($project.'/.claude/settings.json'))->toBeFalse()
        ->and((string) file_get_contents($project.'/.claude/settings.local.json'))->toContain('C:/tools/sloppy.phar')
        ->and(implode("\n", $output->messages()))->toContain('warn: This project has no vendor/bin/sloppy, so the hooks name C:/tools/sloppy.phar')
        // The rules name no path, so they are still shared.
        ->and(is_file($project.'/CLAUDE.md'))->toBeTrue();

    removeTree($project);
});

it('prints what it would write and writes nothing on a dry run', function (): void {
    $project = tempProject(['composer.json' => '{}', 'vendor/bin/sloppy' => '<?php']);
    $output = new RecordingRunnerOutput;

    $code = (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(dryRun: true, binary: '/tools/sloppy.phar'), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and($output->reportBody())->toContain('php \"${CLAUDE_PROJECT_DIR}/vendor/bin/sloppy\" hook stop')
        ->and($output->messages())->toBe(['info: Dry run: .claude/settings.json and CLAUDE.md were not written.'])
        ->and(is_dir($project.'/.claude'))->toBeFalse()
        ->and(is_file($project.'/CLAUDE.md'))->toBeFalse();

    removeTree($project);
});

it('leaves a settings file it cannot read exactly as it was', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php', '.claude/settings.json' => '{"hooks": ']);
    $output = new RecordingRunnerOutput;

    $code = (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(binary: '/tools/sloppy.phar'), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toStartWith('error: .claude/settings.json was left alone. It is not valid JSON')
        ->and((string) file_get_contents($project.'/.claude/settings.json'))->toBe('{"hooks": ');

    removeTree($project);
});

it('reports a settings directory it cannot create', function (): void {
    // A file where the directory should be.
    $project = tempProject(['vendor/bin/sloppy' => '<?php', '.claude' => 'not a directory']);
    $output = new RecordingRunnerOutput;

    $code = (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(binary: '/tools/sloppy.phar'), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toStartWith('error: Could not write .claude/settings.json');

    removeTree($project);
});

it('fails when the ruleset cannot be written, after installing the hooks', function (): void {
    $project = tempProject(['composer.json' => '{}', 'vendor/bin/sloppy' => '<?php']);
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

it('says it kept a settings file that already had its hooks', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php']);
    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions, new RecordingRunnerOutput);
    $output = new RecordingRunnerOutput;

    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions, $output);

    expect($output->messages()[0])->toStartWith('info: Kept .claude/settings.json');

    removeTree($project);
});

it('takes out everything install put in, and leaves the rest byte for byte', function (): void {
    $settings = "{\n    \"model\": \"opus\",\n    \"cleanupPeriodDays\": 12345678901234567890,\n    \"ratio\": 1.0\n}\n";
    $notes = "# Team notes\n\nBe kind.\n";
    $mcp = "{\n  \"mcpServers\": {\n    \"github\": {\n      \"command\": \"gh-mcp\"\n    }\n  }\n}\n";
    $mcpWithSloppy = "{\n  \"mcpServers\": {\n    \"github\": {\n      \"command\": \"gh-mcp\"\n    },\n    \"sloppy\": {\n      \"command\": \"php\",\n      \"args\": [\"vendor/bin/sloppy-mcp\"]\n    }\n  }\n}\n";

    $project = tempProject([
        'vendor/bin/sloppy' => '<?php',
        '.claude/settings.json' => $settings,
        'CLAUDE.md' => $notes,
        '.mcp.json' => $mcpWithSloppy,
    ]);

    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions, new RecordingRunnerOutput);
    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(local: true), new RecordingRunnerOutput);

    expect((string) file_get_contents($project.'/.claude/settings.json'))->toContain('hook stop')
        ->and((string) file_get_contents($project.'/CLAUDE.md'))->toContain('sloppy:begin');

    $output = new RecordingRunnerOutput;
    $code = (new AgentsRunner)->uninstall(agentsSloppy($project), new AgentsOptions, $output);

    expect($code)->toBe(ExitCode::Success)
        ->and(file_get_contents($project.'/.claude/settings.json'))->toBe($settings)
        ->and(file_get_contents($project.'/CLAUDE.md'))->toBe($notes)
        ->and(file_get_contents($project.'/.mcp.json'))->toBe($mcp)
        // Files that held nothing but Sloppy's entries go.
        ->and(is_file($project.'/.claude/settings.local.json'))->toBeFalse()
        ->and(is_file($project.'/CLAUDE.local.md'))->toBeFalse()
        ->and($output->messages())->toContain("info: Removed Sloppy's entries from .claude/settings.json.")
        ->and($output->messages())->toContain("info: Deleted CLAUDE.local.md: Sloppy's entries were all it held.");

    expect((new AgentsRunner)->uninstall(agentsSloppy($project), new AgentsOptions, $output = new RecordingRunnerOutput))->toBe(ExitCode::Success)
        ->and($output->messages())->toBe(['info: Nothing to remove: no Sloppy hooks, ruleset or MCP server entry was found.']);

    removeTree($project);
});

it('removes only the personal files with --local, and nothing at all on a dry run', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php']);
    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions, new RecordingRunnerOutput);
    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(local: true), new RecordingRunnerOutput);

    $dry = new RecordingRunnerOutput;
    (new AgentsRunner)->uninstall(agentsSloppy($project), new AgentsOptions(dryRun: true), $dry);

    expect(is_file($project.'/.claude/settings.json'))->toBeTrue()
        ->and(is_file($project.'/.claude/settings.local.json'))->toBeTrue()
        ->and($dry->messages())->toContain('info: Dry run: nothing was written.');

    (new AgentsRunner)->uninstall(agentsSloppy($project), new AgentsOptions(local: true), new RecordingRunnerOutput);

    expect(is_file($project.'/.claude/settings.json'))->toBeTrue()
        ->and(is_file($project.'/CLAUDE.md'))->toBeTrue()
        ->and(is_file($project.'/.claude/settings.local.json'))->toBeFalse()
        ->and(is_file($project.'/CLAUDE.local.md'))->toBeFalse();

    removeTree($project);
});

it('leaves a file it cannot read as settings alone on uninstall, and says so', function (): void {
    $project = tempProject(['.claude/settings.json' => '{"hooks": ']);
    $output = new RecordingRunnerOutput;

    $code = (new AgentsRunner)->uninstall(agentsSloppy($project), new AgentsOptions, $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages()[0])->toStartWith('error: .claude/settings.json was left alone. It is not valid JSON')
        ->and(file_get_contents($project.'/.claude/settings.json'))->toBe('{"hooks": ');

    removeTree($project);
});

it('deletes the Boost guideline it wrote, and only that one', function (): void {
    $project = tempProject([
        '.ai/guidelines/sloppy.blade.php' => RulesetGenerator::BOOST_MARKER."\n@verbatim\nrules\n@endverbatim\n",
    ]);

    (new AgentsRunner)->uninstall(agentsSloppy($project), new AgentsOptions, new RecordingRunnerOutput);
    expect(is_file($project.'/.ai/guidelines/sloppy.blade.php'))->toBeFalse();

    file_put_contents($project.'/.ai/guidelines/sloppy.blade.php', "Our own guideline.\n");
    (new AgentsRunner)->uninstall(agentsSloppy($project), new AgentsOptions, new RecordingRunnerOutput);
    expect(file_get_contents($project.'/.ai/guidelines/sloppy.blade.php'))->toBe("Our own guideline.\n");

    removeTree($project);
});

it('wires up every agent it is asked to, each with its own ruleset', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php']);
    $output = new RecordingRunnerOutput;

    $code = (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(hosts: [AgentHost::Gemini, AgentHost::Codex]), $output);

    $messages = implode("\n", $output->messages());

    expect($code)->toBe(ExitCode::Success)
        ->and((string) file_get_contents($project.'/.gemini/settings.json'))->toContain('hook post-edit --host=gemini')
        ->and((string) file_get_contents($project.'/.codex/hooks.json'))->toContain('php vendor/bin/sloppy hook stop --host=codex')
        ->and((string) file_get_contents($project.'/GEMINI.md'))->toContain('### SL101 God Method')
        ->and((string) file_get_contents($project.'/AGENTS.md'))->toContain('### SL101 God Method')
        // Claude Code was not asked for.
        ->and(is_dir($project.'/.claude'))->toBeFalse()
        ->and(is_file($project.'/CLAUDE.md'))->toBeFalse()
        ->and($messages)->toContain('info: Created .gemini/settings.json: Gemini CLI now runs Sloppy')
        ->and($messages)->toContain('run /hooks in Codex')
        ->and($messages)->not->toContain('MCP');

    removeTree($project);
});

it('writes the Boost guideline once for every agent where Boost is installed', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php', 'boost.json' => '{}']);

    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(hosts: [AgentHost::ClaudeCode, AgentHost::Codex]), new RecordingRunnerOutput);

    expect(is_file($project.'/.ai/guidelines/sloppy.blade.php'))->toBeTrue()
        ->and(is_file($project.'/AGENTS.md'))->toBeFalse()
        ->and(is_file($project.'/CLAUDE.md'))->toBeFalse();

    removeTree($project);
});

it('names the Boost guideline, not the rulesets it stands in for, on a dry run', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php', 'boost.json' => '{}']);
    $output = new RecordingRunnerOutput;

    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(dryRun: true, hosts: [AgentHost::ClaudeCode, AgentHost::Gemini]), $output);

    expect($output->messages())->toBe(['info: Dry run: .claude/settings.json, .gemini/settings.json and .ai/guidelines/sloppy.blade.php were not written.']);

    removeTree($project);
});

it('skips an agent with no personal settings file when asked for --local, and installs the rest', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php']);
    $output = new RecordingRunnerOutput;

    $code = (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(local: true, hosts: [AgentHost::ClaudeCode, AgentHost::Gemini]), $output);

    expect($code)->toBe(ExitCode::Success)
        ->and(is_file($project.'/.claude/settings.local.json'))->toBeTrue()
        ->and(is_dir($project.'/.gemini'))->toBeFalse()
        ->and(is_file($project.'/GEMINI.md'))->toBeFalse()
        ->and($output->messages()[0])->toStartWith('info: Created .claude/settings.local.json')
        ->and($output->messages())->toContain('warn: Gemini CLI was skipped: it has no personal settings file inside the project, so its hooks cannot be kept out of git. Install without --local to share them with the team.');

    removeTree($project);
});

it('fails when no agent it was asked for can be installed', function (): void {
    $project = tempProject(['composer.json' => '{}']);
    $output = new RecordingRunnerOutput;

    $code = (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(binary: '/tools/sloppy.phar', hosts: [AgentHost::Codex]), $output);

    expect($code)->toBe(ExitCode::Error)
        ->and($output->messages())->toBe([
            'warn: Codex was skipped: this project has no vendor/bin/sloppy for its hooks to run, and Codex has no personal settings file to hold a path on this machine. Run composer require --dev heyosseus/sloppy and install again.',
            'error: No agent\'s hooks were installed.',
        ])
        ->and(is_dir($project.'/.codex'))->toBeFalse();

    removeTree($project);
});

it('names every file a dry run for several agents would have written', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php']);
    $output = new RecordingRunnerOutput;

    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(dryRun: true, hosts: [AgentHost::ClaudeCode, AgentHost::Gemini]), $output);

    expect($output->messages())->toBe(['info: Dry run: .claude/settings.json, .gemini/settings.json, CLAUDE.md and GEMINI.md were not written.'])
        ->and(is_dir($project.'/.gemini'))->toBeFalse();

    removeTree($project);
});

it('takes every agent back out, Gemini\'s MCP server included, or only the ones named', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php']);
    $all = [AgentHost::ClaudeCode, AgentHost::Gemini, AgentHost::Codex];
    (new AgentsRunner)->run(agentsSloppy($project), new AgentsOptions(hosts: $all), new RecordingRunnerOutput);

    $gemini = (string) file_get_contents($project.'/.gemini/settings.json');
    file_put_contents($project.'/.gemini/settings.json', str_replace('"hooks"', '"mcpServers": {"sloppy": {"command": "php", "args": ["vendor/bin/sloppy-mcp"]}},'."\n  ".'"hooks"', $gemini));

    $code = (new AgentsRunner)->uninstall(agentsSloppy($project), new AgentsOptions(hosts: [AgentHost::Codex]), new RecordingRunnerOutput);

    expect($code)->toBe(ExitCode::Success)
        ->and(is_file($project.'/.codex/hooks.json'))->toBeFalse()
        ->and(is_file($project.'/AGENTS.md'))->toBeFalse()
        ->and(is_file($project.'/.gemini/settings.json'))->toBeTrue()
        ->and(is_file($project.'/.claude/settings.json'))->toBeTrue();

    $output = new RecordingRunnerOutput;
    (new AgentsRunner)->uninstall(agentsSloppy($project), new AgentsOptions, $output);

    expect(is_file($project.'/.gemini/settings.json'))->toBeFalse()
        ->and(is_file($project.'/GEMINI.md'))->toBeFalse()
        ->and(is_file($project.'/.claude/settings.json'))->toBeFalse()
        ->and($output->messages())->toContain("info: Deleted .gemini/settings.json: Sloppy's entries were all it held.");

    removeTree($project);
});

it('parses the agents it is given, once each', function (): void {
    expect(AgentsOptions::parseHosts(['gemini', 'Codex', 'gemini']))->toBe([AgentHost::Gemini, AgentHost::Codex])
        ->and((new AgentsOptions)->installHosts())->toBe([AgentHost::ClaudeCode])
        ->and((new AgentsOptions)->uninstallHosts())->toBe(AgentHost::cases());
});
