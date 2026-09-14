<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Integrations\Tooling;

/**
 * What happened when another tool was run.
 */
final readonly class ToolResult
{
    public function __construct(
        public string $tool,
        public int $exitCode,
        public string $output,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * The tail of the output, which is where a formatter or a rewriter puts
     * its summary and where its error goes if it had one.
     */
    public function tail(int $lines = 12): string
    {
        $all = preg_split('/\R/', trim($this->output)) ?: [];

        return implode(PHP_EOL, array_slice($all, -max(1, $lines)));
    }
}
