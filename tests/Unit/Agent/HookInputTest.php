<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\HookEvent;
use Heyosseus\Sloppy\Agent\HookPayload;

it('reads the edited file, the working directory and the retry flag', function (): void {
    $payload = HookPayload::fromJson(json_encode([
        'cwd' => '/project',
        'stop_hook_active' => true,
        'tool_input' => ['file_path' => ' /project/app/A.php '],
    ], JSON_THROW_ON_ERROR));

    expect($payload->filePaths)->toBe(['/project/app/A.php'])
        ->and($payload->cwd)->toBe('/project')
        ->and($payload->stopHookActive)->toBeTrue();
});

it('treats an empty payload as no payload', function (): void {
    $payload = HookPayload::fromJson('  ');

    expect($payload->filePaths)->toBe([])
        ->and($payload->cwd)->toBeNull()
        ->and($payload->stopHookActive)->toBeFalse();
});

it('ignores fields of the wrong type rather than guessing', function (): void {
    $payload = HookPayload::fromJson('{"cwd": 3, "stop_hook_active": "yes", "tool_input": "app/A.php"}');

    expect($payload->filePaths)->toBe([])
        ->and($payload->cwd)->toBeNull()
        ->and($payload->stopHookActive)->toBeFalse();
});

it('refuses input that is not a JSON object', function (string $json, string $message): void {
    expect(fn (): HookPayload => HookPayload::fromJson($json))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'broken' => ['{nope', 'not valid JSON'],
    'scalar' => ['"text"', 'not a JSON object'],
]);

it('parses the hook events by name', function (): void {
    expect(HookEvent::parse(' Post-Edit '))->toBe(HookEvent::PostEdit)
        ->and(HookEvent::parse('stop'))->toBe(HookEvent::Stop)
        ->and(fn (): HookEvent => HookEvent::parse('pre-commit'))
        ->toThrow(InvalidArgumentException::class, 'Unknown hook event [pre-commit]. Expected one of: post-edit, stop.');
});

it('reads every file a Codex patch writes, against the session directory', function (): void {
    $patch = "*** Begin Patch\n*** Add File: app/New.php\n+<?php\n*** Update File: app/Old.php\n*** Move to: app/Moved.php\n@@\n-a\n+b\n*** Delete File: app/Gone.php\n*** Update File: /abs/app/Abs.php\n*** Update File: app/New.php\n*** End Patch\n";

    $payload = HookPayload::fromJson(json_encode([
        'cwd' => 'C:\\project\\',
        'tool_name' => 'apply_patch',
        'tool_input' => ['command' => $patch],
    ], JSON_THROW_ON_ERROR));

    expect($payload->filePaths)->toBe([
        'C:/project/app/New.php',
        'C:/project/app/Old.php',
        'C:/project/app/Moved.php',
        '/abs/app/Abs.php',
    ]);
});

it('leaves patch paths relative when there is no session directory, and reads a plain command as no edit', function (): void {
    expect(HookPayload::fromJson('{"tool_input": {"command": "*** Update File: app/A.php"}}')->filePaths)->toBe(['app/A.php'])
        ->and(HookPayload::fromJson('{"tool_input": {"command": "ls -la"}}')->filePaths)->toBe([]);
});
