<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Integrations\Tooling;

/**
 * Running someone else's binary.
 *
 * Behind an interface because `sloppy fix` is only worth having if it is
 * tested, and a test that really invoked Rector over a temporary project would
 * take longer than the suite it lives in and fail on a machine where Rector is
 * not installed.
 */
interface ToolRunner
{
    /**
     * @param  list<string>  $command
     */
    public function run(string $tool, array $command, string $workingDirectory): ToolResult;
}
