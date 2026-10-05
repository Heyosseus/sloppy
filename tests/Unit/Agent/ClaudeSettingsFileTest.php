<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\ClaudeSettingsFile;

const SLOPPY_COMMAND = 'php "${CLAUDE_PROJECT_DIR}/vendor/bin/sloppy"';

/**
 * @return array<string, mixed>
 */
function decodedSettings(string $json): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('writes both hooks into a file that does not exist yet', function (): void {
    $settings = decodedSettings(ClaudeSettingsFile::merge(null, SLOPPY_COMMAND));

    expect($settings)->toBe([
        'hooks' => [
            'PostToolUse' => [[
                'matcher' => 'Edit|Write|MultiEdit',
                'hooks' => [['type' => 'command', 'command' => SLOPPY_COMMAND.' hook post-edit']],
            ]],
            'Stop' => [[
                'hooks' => [['type' => 'command', 'command' => SLOPPY_COMMAND.' hook stop']],
            ]],
        ],
    ]);
});

it('indents with two spaces, as Claude Code writes the file itself', function (): void {
    $json = ClaudeSettingsFile::merge('', SLOPPY_COMMAND);

    expect($json)->toStartWith("{\n  \"hooks\": {\n    \"PostToolUse\": [")
        ->and($json)->toEndWith("}\n");
});

it('leaves every other setting and every other hook exactly where it was', function (): void {
    $existing = <<<'JSON'
    {
      "permissions": {"allow": ["Bash(composer test)"], "deny": []},
      "env": {},
      "hooks": {
        "PostToolUse": [
          {"matcher": "Write", "hooks": [{"type": "command", "command": "vendor/bin/pint --dirty"}]}
        ],
        "PreToolUse": [
          {"matcher": "Bash", "hooks": [{"type": "command", "command": "./guard.sh"}]}
        ]
      }
    }
    JSON;

    $json = ClaudeSettingsFile::merge($existing, SLOPPY_COMMAND);
    $settings = decodedSettings($json);

    expect($settings['permissions'])->toBe(['allow' => ['Bash(composer test)'], 'deny' => []])
        // An empty object stays an object: Claude Code rejects "env": [].
        ->and($json)->toContain('"env": {}')
        ->and($settings['hooks']['PreToolUse'][0]['hooks'][0]['command'])->toBe('./guard.sh')
        ->and($settings['hooks']['PostToolUse'])->toHaveCount(2)
        ->and($settings['hooks']['PostToolUse'][0]['hooks'][0]['command'])->toBe('vendor/bin/pint --dirty')
        ->and($settings['hooks']['PostToolUse'][1]['hooks'][0]['command'])->toBe(SLOPPY_COMMAND.' hook post-edit');
});

it('replaces its own entries on a second install rather than adding more', function (): void {
    $first = ClaudeSettingsFile::merge(null, 'php "/old/place/sloppy.phar"');
    $second = decodedSettings(ClaudeSettingsFile::merge($first, SLOPPY_COMMAND));

    expect($second['hooks']['PostToolUse'])->toHaveCount(1)
        ->and($second['hooks']['Stop'])->toHaveCount(1)
        ->and($second['hooks']['Stop'][0]['hooks'][0]['command'])->toBe(SLOPPY_COMMAND.' hook stop');
});

it('takes only its own hook out of a group it shares with someone else', function (): void {
    $existing = json_encode(['hooks' => ['Stop' => [[
        'hooks' => [
            ['type' => 'command', 'command' => 'notify-send done'],
            ['type' => 'command', 'command' => 'vendor/bin/sloppy hook stop'],
        ],
    ]]]], JSON_THROW_ON_ERROR);

    $settings = decodedSettings(ClaudeSettingsFile::merge($existing, SLOPPY_COMMAND));

    expect($settings['hooks']['Stop'])->toHaveCount(2)
        ->and($settings['hooks']['Stop'][0]['hooks'])->toBe([['type' => 'command', 'command' => 'notify-send done']]);
});

it('keeps groups it does not understand rather than dropping them', function (): void {
    $existing = '{"hooks": {"Stop": ["something custom", {"matcher": "x"}]}}';

    $settings = decodedSettings(ClaudeSettingsFile::merge($existing, SLOPPY_COMMAND));

    expect($settings['hooks']['Stop'])->toHaveCount(3)
        ->and($settings['hooks']['Stop'][0])->toBe('something custom')
        ->and($settings['hooks']['Stop'][1])->toBe(['matcher' => 'x']);
});

it('recognises the entries it wrote', function (): void {
    expect(ClaudeSettingsFile::isSloppyHook(SLOPPY_COMMAND.' hook post-edit'))->toBeTrue()
        ->and(ClaudeSettingsFile::isSloppyHook('C:/tools/sloppy.phar hook stop'))->toBeTrue()
        ->and(ClaudeSettingsFile::isSloppyHook('vendor/bin/sloppy diff'))->toBeFalse()
        ->and(ClaudeSettingsFile::isSloppyHook('./hook stop'))->toBeFalse();
});

it('refuses to rewrite a file it cannot read as settings', function (string $existing, string $message): void {
    expect(fn (): string => ClaudeSettingsFile::merge($existing, SLOPPY_COMMAND))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'broken JSON' => ['{"hooks": ', 'is not valid JSON'],
    'not an object' => ['[1, 2]', 'is not a JSON object'],
    'hooks not an object' => ['{"hooks": []}', '"hooks" is not an object'],
    'event not a list' => ['{"hooks": {"Stop": {}}}', '"hooks.Stop" is not a list'],
]);

it('keeps numbers, indentation and line endings of the settings it did not touch byte for byte', function (): void {
    $existing = "{\r\n    \"cleanupPeriodDays\": 12345678901234567890,\r\n    \"ratio\": 1.0,\r\n    \"small\": 1e3,\r\n    \"env\": {}\r\n}\r\n";

    $json = ClaudeSettingsFile::merge($existing, SLOPPY_COMMAND);

    expect($json)->toStartWith("{\r\n    \"cleanupPeriodDays\": 12345678901234567890,\r\n    \"ratio\": 1.0,\r\n    \"small\": 1e3,\r\n    \"env\": {},\r\n    \"hooks\": {\r\n        \"PostToolUse\": [")
        ->and($json)->toEndWith("}\r\n")
        ->and($json)->not->toContain("\n\n");
});

it('writes back a file it did not need to change exactly as it was', function (): void {
    $installed = ClaudeSettingsFile::merge('{"model":"opus","n":1.0}', SLOPPY_COMMAND);
    $reformatted = str_replace('  ', "\t", $installed);

    expect(ClaudeSettingsFile::merge($reformatted, SLOPPY_COMMAND))->toBe($reformatted);
});

it('takes its hooks out again, leaving the file as it was before them', function (string $original): void {
    $installed = ClaudeSettingsFile::merge($original, SLOPPY_COMMAND);

    expect($installed)->not->toBe($original)
        ->and(ClaudeSettingsFile::remove($installed))->toBe($original);
})->with([
    'two spaces' => ["{\n  \"model\": \"opus\",\n  \"cleanupPeriodDays\": 12345678901234567890,\n  \"ratio\": 1.0\n}\n"],
    'four spaces, CRLF' => ["{\r\n    \"env\": {},\r\n    \"permissions\": {\r\n        \"allow\": []\r\n    }\r\n}\r\n"],
    'other hooks' => ["{\n  \"hooks\": {\n    \"PostToolUse\": [\n      {\n        \"matcher\": \"Write\",\n        \"hooks\": [\n          {\n            \"type\": \"command\",\n            \"command\": \"vendor/bin/pint --dirty\"\n          }\n        ]\n      }\n    ]\n  }\n}\n"],
]);

it('says a file that held only its hooks is now empty, and leaves files without them alone', function (): void {
    expect(ClaudeSettingsFile::remove(ClaudeSettingsFile::merge(null, SLOPPY_COMMAND)))->toBe('')
        ->and(ClaudeSettingsFile::remove('{"model": "opus", "hooks": {"Stop": []}}'))->toBeNull()
        ->and(ClaudeSettingsFile::remove('{"hooks": "not an object"}'))->toBeNull()
        ->and(fn (): ?string => ClaudeSettingsFile::remove('{"hooks": '))->toThrow(InvalidArgumentException::class, 'It is not valid JSON');
});
