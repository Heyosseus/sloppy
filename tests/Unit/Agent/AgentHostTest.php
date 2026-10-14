<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\AgentHost;
use Heyosseus\Sloppy\Agent\HookEvent;
use Heyosseus\Sloppy\Agent\RulesetFormat;

it('points the hooks at the project\'s own copy when it has one', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php']);

    expect(AgentHost::ClaudeCode->command($project, '/home/me/.composer/vendor/bin/sloppy'))
        ->toBe('php "${CLAUDE_PROJECT_DIR}/vendor/bin/sloppy"');

    removeTree($project);
});

it('points them at the running binary for a global or phar install', function (): void {
    $project = tempProject(['composer.json' => '{}']);

    expect(AgentHost::ClaudeCode->command($project, 'C:\\tools\\sloppy.phar'))
        ->toBe('php "C:/tools/sloppy.phar"');

    removeTree($project);
});

it('keeps shared hooks in the committed settings, and personal ones out of git', function (): void {
    expect(AgentHost::ClaudeCode->settingsFile(local: false))->toBe('.claude/settings.json')
        ->and(AgentHost::ClaudeCode->settingsFile(local: true))->toBe('.claude/settings.local.json')
        ->and(AgentHost::ClaudeCode->label())->toBe('Claude Code');
});

it('names this machine\'s PHP only in a personal settings file', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php']);

    expect(AgentHost::ClaudeCode->command($project, '', local: true, phpBinary: 'C:\\php\\8.5\\php.exe'))
        ->toBe('C:/php/8.5/php.exe "${CLAUDE_PROJECT_DIR}/vendor/bin/sloppy"')
        ->and(AgentHost::ClaudeCode->command($project, '', local: false, phpBinary: 'C:\\php\\8.5\\php.exe'))
        ->toBe('php "${CLAUDE_PROJECT_DIR}/vendor/bin/sloppy"')
        // Quoting it would make it a string to PowerShell, not a command.
        ->and(AgentHost::ClaudeCode->command($project, '', local: true, phpBinary: 'C:\\Program Files\\PHP\\php.exe'))
        ->toBe('php "${CLAUDE_PROJECT_DIR}/vendor/bin/sloppy"')
        ->and(AgentHost::ClaudeCode->hasProjectCopy($project))->toBeTrue();

    removeTree($project);
});

it('parses the agents by name', function (): void {
    expect(AgentHost::parse(' Gemini '))->toBe(AgentHost::Gemini)
        ->and(AgentHost::parse('codex'))->toBe(AgentHost::Codex)
        ->and(fn (): AgentHost => AgentHost::parse('cursor'))
        ->toThrow(InvalidArgumentException::class, 'Unknown agent [cursor]. Expected one of: claude, gemini, codex.');
});

it('knows where each agent reads its hooks, and what it calls its events', function (): void {
    expect(AgentHost::Gemini->settingsFile(local: true))->toBe('.gemini/settings.json')
        ->and(AgentHost::Codex->settingsFile())->toBe('.codex/hooks.json')
        ->and(AgentHost::Gemini->label())->toBe('Gemini CLI')
        ->and(AgentHost::Codex->label())->toBe('Codex')
        ->and([AgentHost::ClaudeCode->editEvent(), AgentHost::Gemini->editEvent(), AgentHost::Codex->editEvent()])
        ->toBe(['PostToolUse', 'AfterTool', 'PostToolUse'])
        ->and([AgentHost::ClaudeCode->stopEvent(), AgentHost::Gemini->stopEvent(), AgentHost::Codex->stopEvent()])
        ->toBe(['Stop', 'AfterAgent', 'Stop'])
        ->and(AgentHost::Gemini->editTools())->toBe('write_file|replace')
        ->and(AgentHost::Codex->editTools())->toBe('apply_patch|Edit|Write')
        ->and(AgentHost::ClaudeCode->hasLocalFile())->toBeTrue()
        ->and(AgentHost::Gemini->hasLocalFile())->toBeFalse()
        ->and(AgentHost::ClaudeCode->rulesetFormat())->toBeNull()
        ->and(AgentHost::Gemini->rulesetFormat())->toBe(RulesetFormat::Gemini)
        ->and(AgentHost::Codex->rulesetFormat())->toBe(RulesetFormat::Agents);
});

it('reaches the project copy the way each agent can', function (): void {
    $project = tempProject(['vendor/bin/sloppy' => '<?php']);

    expect(AgentHost::Gemini->command($project, '/elsewhere/sloppy'))->toBe('php "$GEMINI_PROJECT_DIR/vendor/bin/sloppy"')
        ->and(AgentHost::Codex->command($project, '/elsewhere/sloppy'))->toBe('php vendor/bin/sloppy');

    removeTree($project);
});

it('names the agent in the hook command, except for Claude Code', function (): void {
    expect(AgentHost::ClaudeCode->hookCommand('php sloppy', HookEvent::Stop))->toBe('php sloppy hook stop')
        ->and(AgentHost::Gemini->hookCommand('php sloppy', HookEvent::PostEdit))->toBe('php sloppy hook post-edit --host=gemini')
        ->and(AgentHost::Codex->hookCommand('php sloppy', HookEvent::Stop))->toBe('php sloppy hook stop --host=codex');
});

it('appends to a Gemini tool result rather than replacing it, and blocks everywhere else', function (): void {
    expect(AgentHost::Gemini->blockOutput(HookEvent::PostEdit, 'fix it'))
        ->toBe(['hookSpecificOutput' => ['hookEventName' => 'AfterTool', 'additionalContext' => 'fix it']])
        ->and(AgentHost::Gemini->blockOutput(HookEvent::Stop, 'fix it'))->toBe(['decision' => 'block', 'reason' => 'fix it'])
        ->and(AgentHost::Codex->blockOutput(HookEvent::PostEdit, 'fix it'))->toBe(['decision' => 'block', 'reason' => 'fix it'])
        ->and(AgentHost::ClaudeCode->noticesOnStdout())->toBeTrue()
        ->and(AgentHost::Codex->noticesOnStdout())->toBeFalse();
});
