<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Mcp\McpServer;
use Heyosseus\Sloppy\Mcp\StdioTransport;

/**
 * Serve a transcript and read back what the server wrote.
 *
 * @param  list<string>  $messages
 * @return array{0: int, 1: list<string>}
 */
function serveLines(array $messages): array
{
    $root = tempProject([
        'in.jsonl' => implode("\n", $messages)."\n",
        'out.jsonl' => '',
    ]);

    $answered = (new StdioTransport(McpServer::default($root)))->serve(
        new SplFileObject($root.'/in.jsonl'),
        new SplFileObject($root.'/out.jsonl', 'w'),
    );

    $written = array_values(array_filter(explode("\n", (string) file_get_contents($root.'/out.jsonl'))));

    removeTree($root);

    return [$answered, $written];
}

it('answers each request and stays quiet for notifications and blank lines', function (): void {
    [$answered, $written] = serveLines([
        '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
        '{"jsonrpc":"2.0","method":"notifications/initialized"}',
        '',
        '   ',
        '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
    ]);

    expect($answered)->toBe(2)
        ->and($written)->toHaveCount(2);

    /** @var array{id: int, result: array{tools: list<array{name: string}>}} $second */
    $second = json_decode($written[1], true, 512, JSON_THROW_ON_ERROR);

    expect($second['id'])->toBe(2)
        ->and(array_column($second['result']['tools'], 'name'))->toContain('sloppy_scan');
});

it('serves nothing from an empty stream', function (): void {
    [$answered, $written] = serveLines([]);

    expect($answered)->toBe(0)
        ->and($written)->toBe([]);
});

it('writes one JSON object per line, so a client can read them as they arrive', function (): void {
    [, $written] = serveLines([
        '{"jsonrpc":"2.0","id":1,"method":"ping"}',
        '{"jsonrpc":"2.0","id":2,"method":"ping"}',
    ]);

    expect($written)->toHaveCount(2);

    foreach ($written as $line) {
        expect($line)->toStartWith('{')->and($line)->toEndWith('}');
    }
});
