<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\AgentHost;

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
