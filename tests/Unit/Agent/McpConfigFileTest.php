<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Agent\McpConfigFile;

it('takes the Sloppy server out and leaves every other server as it was', function (): void {
    $file = "{\n    \"mcpServers\": {\n        \"github\": {\n            \"command\": \"gh-mcp\",\n            \"timeout\": 1.0\n        },\n        \"sloppy\": {\n            \"command\": \"php\",\n            \"args\": [\"vendor/bin/sloppy-mcp\"]\n        }\n    }\n}\n";

    expect(McpConfigFile::remove($file))->toBe("{\n    \"mcpServers\": {\n        \"github\": {\n            \"command\": \"gh-mcp\",\n            \"timeout\": 1.0\n        }\n    }\n}\n");
});

it('recognises Sloppy under another name, through any entry point', function (string $name, string $server): void {
    expect(McpConfigFile::isOurs($name, json_decode($server)))->toBeTrue();
})->with([
    'script' => ['analyser', '{"command": "vendor/bin/sloppy-mcp"}'],
    'php script' => ['analyser', '{"command": "php", "args": ["vendor/bin/sloppy-mcp"]}'],
    'subcommand' => ['analyser', '{"command": "php", "args": ["vendor/bin/sloppy", "mcp"]}'],
    'artisan' => ['analyser', '{"command": "php", "args": ["artisan", "sloppy:mcp"]}'],
    'by name' => ['sloppy', '{}'],
]);

it('leaves other servers, and a file without Sloppy, alone', function (): void {
    expect(McpConfigFile::isOurs('github', json_decode('{"command": "gh-mcp", "args": ["--sloppy-mode"]}')))->toBeFalse()
        ->and(McpConfigFile::remove('{"mcpServers": {"github": {"command": "gh-mcp"}}}'))->toBeNull()
        ->and(McpConfigFile::remove('{"mcpServers": {"sloppy": {"command": "php", "args": ["vendor/bin/sloppy-mcp"]}}}'))->toBe('')
        ->and(fn (): ?string => McpConfigFile::remove('{"mcpServers": []}'))->toThrow(InvalidArgumentException::class, '"mcpServers" is not an object.');
});
