<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

use InvalidArgumentException;
use stdClass;

/**
 * Taking the MCP server back out of a project's `.mcp.json`.
 *
 * `agents install` does not write this file -- registering a server is the
 * team's decision, and the docs show the entry -- but `agents uninstall`
 * takes out whatever entry runs Sloppy, so removing Sloppy from the agent
 * leaves nothing behind. Other servers in the file, and the file's own
 * formatting, are left as they were.
 */
final readonly class McpConfigFile
{
    public const string SERVER = 'sloppy';

    /**
     * The file without the Sloppy server.
     *
     * @return string|null The new contents; '' when nothing at all is left, null when no Sloppy server was there.
     *
     * @throws InvalidArgumentException When the existing file is not an MCP configuration object.
     */
    public static function remove(string $existing): ?string
    {
        $document = JsonDocument::parse($existing);
        $servers = self::servers($document);
        $removed = false;

        foreach (get_object_vars($servers) as $name => $server) {
            if (self::isOurs((string) $name, $server)) {
                unset($servers->{$name});
                $removed = true;
            }
        }

        if (! $removed) {
            return null;
        }

        if (get_object_vars($servers) === []) {
            unset($document->data->mcpServers);
        }

        return $document->isEmpty() ? '' : $document->render();
    }

    /**
     * Whether a server entry runs Sloppy's MCP server, under whatever name
     * and through whichever entry point: the standalone script, `sloppy mcp`
     * or `artisan sloppy:mcp`.
     */
    public static function isOurs(string $name, mixed $server): bool
    {
        if ($name === self::SERVER) {
            return true;
        }

        if (! $server instanceof stdClass) {
            return false;
        }

        $parts = [$server->command ?? ''];

        foreach (is_array($server->args ?? null) ? $server->args : [] as $argument) {
            $parts[] = $argument;
        }

        $line = implode(' ', array_filter($parts, is_string(...)));

        return preg_match('/sloppy-mcp\b|sloppy["\s]+mcp\b|sloppy:mcp\b/i', $line) === 1;
    }

    private static function servers(JsonDocument $document): stdClass
    {
        $servers = $document->data->mcpServers ?? new stdClass;

        if (! $servers instanceof stdClass) {
            throw new InvalidArgumentException('"mcpServers" is not an object.');
        }

        return $servers;
    }
}
