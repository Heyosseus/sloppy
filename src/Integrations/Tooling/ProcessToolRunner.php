<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Integrations\Tooling;

use Symfony\Component\Process\Process;

/**
 * The real {@see ToolRunner}: a child process, with its output kept.
 *
 * Standard error is folded into the output because a rewriter that failed says
 * why there, and a message the user cannot see is the same as no message.
 */
final readonly class ProcessToolRunner implements ToolRunner
{
    /**
     * Rector over a large project is slow but not unbounded; ten minutes is
     * long enough for an honest run and short enough that a hung one is not
     * mistaken for a working one.
     */
    public function __construct(private float $timeout = 600.0) {}

    public function run(string $tool, array $command, string $workingDirectory): ToolResult
    {
        $process = new Process($command, $workingDirectory, timeout: $this->timeout);
        $process->run();

        return new ToolResult(
            tool: $tool,
            exitCode: $process->getExitCode() ?? 1,
            output: $process->getOutput().$process->getErrorOutput(),
        );
    }
}
