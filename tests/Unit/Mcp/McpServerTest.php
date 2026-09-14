<?php

declare(strict_types=1);

use Heyosseus\Sloppy\Mcp\McpServer;
use Heyosseus\Sloppy\Mcp\McpTool;
use Heyosseus\Sloppy\Sloppy;

/**
 * A tool that answers however the test needs it to.
 */
function stubTool(string $name, string $answer = 'the answer', bool $throws = false): McpTool
{
    return new readonly class($name, $answer, $throws) implements McpTool
    {
        public function __construct(
            private string $toolName,
            private string $answer,
            private bool $throws,
        ) {}

        public function name(): string
        {
            return $this->toolName;
        }

        public function description(): string
        {
            return 'A tool for the tests.';
        }

        public function inputSchema(): array
        {
            return ['type' => 'object', 'properties' => [], 'required' => []];
        }

        public function call(array $arguments): string
        {
            if ($this->throws) {
                throw new RuntimeException('this is not a git repository');
            }

            return $this->answer.(isset($arguments['suffix']) && is_string($arguments['suffix']) ? $arguments['suffix'] : '');
        }
    };
}

/**
 * @return array<string, mixed>
 */
function decoded(?string $line): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('answers the handshake with its own protocol version and instructions', function (): void {
    $server = new McpServer([stubTool('sloppy_scan')]);

    $response = decoded($server->handleLine('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'));

    /** @var array{protocolVersion: string, capabilities: array<string, mixed>, serverInfo: array{name: string, version: string}, instructions: string} $result */
    $result = $response['result'];

    expect($response['jsonrpc'])->toBe('2.0')
        ->and($response['id'])->toBe(1)
        ->and($result['protocolVersion'])->toBe(McpServer::PROTOCOL_VERSION)
        ->and($result['capabilities'])->toBe(['tools' => ['listChanged' => false]])
        ->and($result['serverInfo'])->toBe(['name' => 'sloppy', 'version' => Sloppy::VERSION])
        ->and($result['instructions'])->toContain('sloppy_diff');
});

it('lists its tools with their schemas', function (): void {
    $server = new McpServer([stubTool('sloppy_scan'), stubTool('sloppy_diff')]);

    $response = decoded($server->handleLine('{"jsonrpc":"2.0","id":2,"method":"tools/list"}'));

    /** @var array{tools: list<array{name: string, description: string, inputSchema: array<string, mixed>}>} $result */
    $result = $response['result'];

    expect($result['tools'])->toHaveCount(2)
        ->and($result['tools'][0]['name'])->toBe('sloppy_scan')
        ->and($result['tools'][0]['inputSchema']['type'])->toBe('object')
        ->and($result['tools'][1]['name'])->toBe('sloppy_diff')
        ->and($server->tools())->toHaveCount(2);
});

it('answers a ping', function (): void {
    expect(decoded((new McpServer([]))->handleLine('{"jsonrpc":"2.0","id":3,"method":"ping"}'))['result'])->toBe([]);
});

it('calls a tool and returns its text', function (): void {
    $server = new McpServer([stubTool('sloppy_scan', 'no findings')]);

    $response = decoded($server->handleLine(
        '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"sloppy_scan","arguments":{"suffix":" here"}}}'
    ));

    expect($response['result'])->toBe([
        'content' => [['type' => 'text', 'text' => 'no findings here']],
        'isError' => false,
    ]);
});

it('reports a tool failure as an answer the model can act on, not a protocol error', function (): void {
    $server = new McpServer([stubTool('sloppy_diff', throws: true)]);

    $response = decoded($server->handleLine('{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"sloppy_diff"}}'));

    /** @var array{content: list<array{text: string}>, isError: bool} $result */
    $result = $response['result'];

    expect($response)->not->toHaveKey('error')
        ->and($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toBe('this is not a git repository');
});

it('treats missing arguments as no arguments', function (): void {
    $server = new McpServer([stubTool('sloppy_scan', 'called')]);

    $response = decoded($server->handleLine('{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"sloppy_scan","arguments":"nope"}}'));

    /** @var array{content: list<array{text: string}>} $result */
    $result = $response['result'];

    expect($result['content'][0]['text'])->toBe('called');
});

it('rejects a call with no tool name, and one naming a tool it does not have', function (): void {
    $server = new McpServer([stubTool('sloppy_scan')]);

    $noName = decoded($server->handleLine('{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{}}'));
    $unknown = decoded($server->handleLine('{"jsonrpc":"2.0","id":8,"method":"tools/call","params":{"name":"rm_rf"}}'));
    $noParams = decoded($server->handleLine('{"jsonrpc":"2.0","id":9,"method":"tools/call"}'));

    /** @var array{code: int, message: string} $noNameError */
    $noNameError = $noName['error'];

    /** @var array{code: int, message: string} $unknownError */
    $unknownError = $unknown['error'];

    expect($noNameError['code'])->toBe(-32602)
        ->and($noNameError['message'])->toBe('A tools/call needs a string "name".')
        ->and($unknownError['message'])->toBe('Unknown tool [rm_rf].')
        ->and($noParams)->toHaveKey('error');
});

it('rejects an unknown method and a request with no method', function (): void {
    $server = new McpServer([]);

    /** @var array{code: int, message: string} $unknown */
    $unknown = decoded($server->handleLine('{"jsonrpc":"2.0","id":10,"method":"resources/list"}'))['error'];

    /** @var array{code: int, message: string} $malformed */
    $malformed = decoded($server->handleLine('{"jsonrpc":"2.0","id":11}'))['error'];

    expect($unknown['code'])->toBe(-32601)
        ->and($unknown['message'])->toBe('Unknown method [resources/list].')
        ->and($malformed['code'])->toBe(-32600);
});

it('says nothing at all to a notification', function (): void {
    $server = new McpServer([]);

    expect($server->handleLine('{"jsonrpc":"2.0","method":"notifications/initialized"}'))->toBeNull()
        ->and($server->handle(['method' => 'notifications/cancelled']))->toBeNull();
});

it('reports unparseable JSON as a parse error', function (): void {
    /** @var array{code: int, message: string} $error */
    $error = decoded((new McpServer([]))->handleLine('{not json'))['error'];

    expect($error['code'])->toBe(-32700)
        ->and($error['message'])->toBe('Invalid JSON.');
});

it('reports a response it could not encode rather than writing half of one', function (): void {
    // A finding quoting a byte sequence that is not valid UTF-8 cannot be JSON.
    $server = new McpServer([stubTool('sloppy_scan', "bad \xB1\x31 bytes")]);

    $line = (string) $server->handleLine('{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"sloppy_scan"}}');

    /** @var array{error: array{code: int, message: string}} $response */
    $response = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

    expect($response['error']['code'])->toBe(-32603)
        ->and($response['error']['message'])->toBe('The response could not be encoded.');
});

it('ships the four tools a coding agent needs', function (): void {
    $names = array_map(
        static fn (McpTool $tool): string => $tool->name(),
        McpServer::default(dirname(__DIR__, 3))->tools(),
    );

    expect($names)->toBe(['sloppy_scan', 'sloppy_diff', 'sloppy_rules', 'sloppy_health']);
});
