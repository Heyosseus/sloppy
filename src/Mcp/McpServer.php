<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp;

use Heyosseus\Sloppy\Mcp\Tools\ArchitecturePromptTool;
use Heyosseus\Sloppy\Mcp\Tools\ArchitectureTool;
use Heyosseus\Sloppy\Mcp\Tools\DiffTool;
use Heyosseus\Sloppy\Mcp\Tools\HealthTool;
use Heyosseus\Sloppy\Mcp\Tools\PlaceTool;
use Heyosseus\Sloppy\Mcp\Tools\RulesTool;
use Heyosseus\Sloppy\Mcp\Tools\ScanTool;
use Heyosseus\Sloppy\Sloppy;
use stdClass;
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
 *
 * Not readonly: the protocol revision is agreed in `initialize` and decides,
 * for the rest of the session, whether a JSON-RPC batch is allowed.
 */
final class McpServer
{
    /**
     * The newest protocol revision this server speaks, and the one it offers
     * a client that asked for a revision it does not know.
     */
    public const string PROTOCOL_VERSION = '2025-06-18';

    /**
     * Every revision this server can speak, newest first. A client asking for
     * one of these gets it back; the specification has the server answer with
     * its own latest otherwise, and the client decide whether to go on.
     */
    public const array SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * The revisions whose JSON-RPC allows a batch. 2025-06-18 removed
     * batching, so a batch sent under it is an invalid request.
     */
    private const array BATCHING_VERSIONS = ['2025-03-26', '2024-11-05'];

    private const int PARSE_ERROR = -32700;

    private const int INVALID_REQUEST = -32600;

    private const int METHOD_NOT_FOUND = -32601;

    private const int INVALID_PARAMS = -32602;

    private const int INTERNAL_ERROR = -32603;

    /** The revision agreed in `initialize`; the latest until then. */
    private string $protocolVersion = self::PROTOCOL_VERSION;

    /**
     * @param  list<McpTool>  $tools
     */
    public function __construct(
        private readonly array $tools,
        private readonly ArgumentValidator $validator = new ArgumentValidator,
    ) {}

    public static function default(string $workingDirectory): self
    {
        $projects = new ProjectResolver($workingDirectory);

        return new self([
            new ScanTool($projects),
            new DiffTool($projects),
            new RulesTool($projects),
            new HealthTool($projects),
            new ArchitectureTool($projects),
            new PlaceTool($projects),
            new ArchitecturePromptTool($projects),
        ]);
    }

    /**
     * Handle one line of newline-delimited JSON-RPC, returning the line to
     * write back, or null for a notification, which must not be answered.
     */
    public function handleLine(string $line): ?string
    {
        // Decoded twice: once as objects, to tell `{}` from `[]` and a request
        // from a batch, and once as arrays, which is what the handlers read.
        $shape = json_decode($line);

        if ($shape === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->encode($this->error(null, self::PARSE_ERROR, 'Invalid JSON.'));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($line, true);

        if (is_array($shape)) {
            return $this->batch($shape, is_array($decoded) ? $decoded : []);
        }

        if (! $shape instanceof stdClass || ! is_array($decoded)) {
            return $this->encode($this->error(null, self::INVALID_REQUEST, 'A request must be a JSON object.'));
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
        $method = $request['method'] ?? null;
        $hasId = array_key_exists('id', $request);
        $id = $request['id'] ?? null;

        if ($hasId && ! is_string($id) && ! is_int($id)) {
            return $this->error(null, self::INVALID_REQUEST, 'A request id must be a string or an integer.');
        }

        if (! is_string($method)) {
            return $this->error($id, self::INVALID_REQUEST, 'A request must carry a string "method".');
        }

        // A notification has no id and, by the specification, no response --
        // including no error response, however wrong it was. `initialized`
        // arrives this way after every handshake.
        if (! $hasId) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->result($id, $this->initialize($request)),
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
     * The revision agreed in `initialize`, or the latest before it.
     */
    public function protocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * Answer a JSON-RPC batch where the agreed revision allows one, and
     * refuse it as one invalid request where it does not -- never with
     * silence, which leaves the client waiting for an answer that will not
     * come.
     *
     * @param  array<mixed>  $shape
     * @param  array<mixed>  $requests
     */
    private function batch(array $shape, array $requests): ?string
    {
        if ($shape === [] || ! in_array($this->protocolVersion, self::BATCHING_VERSIONS, true)) {
            return $this->encode($this->error(null, self::INVALID_REQUEST, $shape === []
                ? 'An empty batch is not a request.'
                : sprintf('Protocol revision %s does not allow JSON-RPC batches; send one request per line.', $this->protocolVersion)));
        }

        $responses = [];

        foreach ($shape as $index => $entry) {
            $request = $requests[$index] ?? null;
            $response = $entry instanceof stdClass && is_array($request)
                ? $this->handle($this->stringKeys($request))
                : $this->error(null, self::INVALID_REQUEST, 'A request must be a JSON object.');

            if ($response !== null) {
                $responses[] = $response;
            }
        }

        if ($responses === []) {
            return null;
        }

        $encoded = json_encode($responses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? $this->encode($this->error(null, self::INTERNAL_ERROR, 'The response could not be encoded.')) : $encoded;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeys(array $values): array
    {
        $keyed = [];

        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function initialize(array $request): array
    {
        $params = $request['params'] ?? null;
        $requested = is_array($params) ? ($params['protocolVersion'] ?? null) : null;

        $this->protocolVersion = is_string($requested) && in_array($requested, self::SUPPORTED_VERSIONS, true)
            ? $requested
            : self::PROTOCOL_VERSION;

        return [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'sloppy', 'version' => Sloppy::VERSION],
            'instructions' => 'Call sloppy_diff before reporting a coding task finished, and fix the findings it '
                .'reports as new. Call sloppy_rules before writing code in an unfamiliar repository, and sloppy_place '
                .'before creating a new class, so it goes where this project\'s architecture expects it.',
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

        /** @var mixed $arguments */
        $arguments = $params['arguments'] ?? [];

        // Arguments that do not fit the schema the tool advertised are an
        // answer the model can act on, so they come back the way a failed
        // tool does rather than as a protocol error.
        $problems = $this->validator->problems($arguments, $tool->inputSchema());

        if ($problems !== []) {
            return $this->result($id, $this->content(
                sprintf('Invalid arguments for %s: %s', $name, implode(' ', $problems)),
                true,
            ));
        }

        /** @var array<string, mixed> $arguments */
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
        // Tools build their text with PHP_EOL, which on Windows is "\r\n". The
        // protocol's text is not a file on this machine, so it gets "\n".
        return [
            'content' => [['type' => 'text', 'text' => str_replace("\r\n", "\n", $text)]],
            'isError' => $isError,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function result(mixed $id, array $result): array
    {
        // An empty PHP array encodes as `[]`, but a result is always a JSON
        // object -- `ping` answers `{}`.
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new stdClass : $result];
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
            ? sprintf('{"jsonrpc":"2.0","id":null,"error":{"code":%d,"message":"The response could not be encoded."}}', self::INTERNAL_ERROR)
            : $encoded;
    }
}
