<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp;

/**
 * One thing an AI agent can ask Sloppy to do.
 *
 * Tools return text rather than a data structure on purpose: the caller is a
 * language model, and a paragraph naming the file, the line and what to do
 * about it is more actionable to it than a nested object it has to summarise
 * for itself before it can act.
 */
interface McpTool
{
    public function name(): string;

    public function description(): string;

    /**
     * The JSON Schema for this tool's arguments, as the protocol requires.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(array $arguments): string;
}
