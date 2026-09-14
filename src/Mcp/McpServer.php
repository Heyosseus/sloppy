<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp;

use Heyosseus\Sloppy\Mcp\Tools\DiffTool;
use Heyosseus\Sloppy\Mcp\Tools\HealthTool;
use Heyosseus\Sloppy\Mcp\Tools\RulesTool;
use Heyosseus\Sloppy\Mcp\Tools\ScanTool;
use Heyosseus\Sloppy\Sloppy;
use Throwable;

/**
 * Sloppy as a tool an AI agent can call on itself.
 *
 * The premise of this package is that a generated codebase acquires a
 * particular kind of debt. The cheapest moment to catch it is before the agent
 * that wrote it says it is done -- so the analyser is offered to the agent in
 * the protocol it already speaks, and a model that has been told to verify its
 * own work can now actually do so.
 *
 * Only the JSON-RPC methods a tools-only server needs are implemented:
 * `initialize`, `tools/list`, `tools/call` and `ping`. Anything else gets a
 * "method not found", which is what the specification asks for and what an
 * unknown method deserves.
 */
final readonly class McpServer
{
    /**
     * The protocol revision this server was written against. Clients send
     * their own in `initialize`; we answer with ours, which is what the
     * specification prescribes when they differ.
     */
    public const string PROTOCOL_VERSION = '2025-06-18';

    private const int PARSE_ERROR = -32700;

    private const int INVALID_REQUEST = -32600;

    private const int METHOD_NOT_FOUND = -32601;

    private const int INVALID_PARAMS = -32602;

    /**
     * @param  list<McpTool>  $tools
     */
    public function __construct(private array $tools) {}

    public static function default(string $workingDirectory): self
    {
        $projects = new ProjectResolver($workingDirectory);

        return new self([
            new ScanTool($projects),
            new DiffTool($projects),
            new RulesTool($projects),
            new HealthTool($projects),
        ]);
    }

    /**
     * Handle one line of newline-delimited JSON-RPC, returning the line to
     * write back, or null for a notification, which must not be answered.
     */
    public function handleLine(string $line): ?string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($line, true);

        if (! is_array($decoded)) {
            return $this->encode($this->error(null, self::PARSE_ERROR, 'Invalid JSON.'));
        }

        /** @var array<string, mixed> $decoded */
        $response = $this->handle($decoded);

        return $response === null ? null : $this->encode($response);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>|null
     */
    public function handle(array $request): ?array
    {
        $id = $request['id'] ?? null;

        // A notification has no id and, by the specification, no response --
        // including no error response, however wrong it was. `initialized`
        // arrives this way after every handshake.
        if ($id === null) {
            return null;
        }

        $method = $request['method'] ?? null;

        if (! is_string($method)) {
            return $this->error($id, self::INVALID_REQUEST, 'A request must carry a string "method".');
        }

        return match ($method) {
            'initialize' => $this->result($id, $this->initialize()),
            'ping' => $this->result($id, []),
            'tools/list' => $this->result($id, ['tools' => $this->descriptors()]),
            'tools/call' => $this->callTool($id, $request),
            default => $this->error($id, self::METHOD_NOT_FOUND, sprintf('Unknown method [%s].', $method)),
        };
    }

    /**
     * @return list<McpTool>
     */
    public function tools(): array
    {
        return $this->tools;
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'sloppy', 'version' => Sloppy::VERSION],
            'instructions' => 'Call sloppy_diff before reporting a coding task finished, and fix the findings it '
                .'reports as new. Call sloppy_rules before writing code in an unfamiliar repository.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function descriptors(): array
    {
        $descriptors = [];

        foreach ($this->tools as $tool) {
            $descriptors[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }

        return $descriptors;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $request): array
    {
        $params = $request['params'] ?? null;
        $params = is_array($params) ? $params : [];
        $name = $params['name'] ?? null;

        if (! is_string($name)) {
            return $this->error($id, self::INVALID_PARAMS, 'A tools/call needs a string "name".');
        }

        $tool = $this->tool($name);

        if (! $tool instanceof McpTool) {
            return $this->error($id, self::INVALID_PARAMS, sprintf('Unknown tool [%s].', $name));
        }

        $arguments = $params['arguments'] ?? null;

        /** @var array<string, mixed> $arguments */
        $arguments = is_array($arguments) ? $arguments : [];

        // A tool that fails answers with a result marked as an error rather
        // than a protocol error: the model asked a reasonable question and the
        // answer -- "this is not a git repository" -- is one it can act on,
        // which a transport-level failure is not.
        try {
            return $this->result($id, $this->content($tool->call($arguments), false));
        } catch (Throwable $exception) {
            return $this->result($id, $this->content($exception->getMessage(), true));
        }
    }

    private function tool(string $name): ?McpTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $text, bool $isError): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function result(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function encode(array $response): string
    {
        $encoded = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false
            ? '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"The response could not be encoded."}}'
            : $encoded;
    }
}
