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

    expect($payload->filePath)->toBe('/project/app/A.php')
        ->and($payload->cwd)->toBe('/project')
        ->and($payload->stopHookActive)->toBeTrue();
});

it('treats an empty payload as no payload', function (): void {
    $payload = HookPayload::fromJson('  ');

    expect($payload->filePath)->toBeNull()
        ->and($payload->cwd)->toBeNull()
        ->and($payload->stopHookActive)->toBeFalse();
});

it('ignores fields of the wrong type rather than guessing', function (): void {
    $payload = HookPayload::fromJson('{"cwd": 3, "stop_hook_active": "yes", "tool_input": "app/A.php"}');

    expect($payload->filePath)->toBeNull()
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
