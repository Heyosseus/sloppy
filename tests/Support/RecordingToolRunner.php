<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests\Support;

use Heyosseus\Sloppy\Integrations\Tooling\ToolResult;
use Heyosseus\Sloppy\Integrations\Tooling\ToolRunner;

/**
 * Records what `sloppy fix` would have run, and answers however the test
 * needs it to.
 *
 * The alternative -- letting the suite invoke Rector over a temporary project
 * -- would take longer than the rest of the suite together and fail on any
 * machine without Rector installed.
 */
final class RecordingToolRunner implements ToolRunner
{
    /** @var list<array{tool: string, command: list<string>, cwd: string}> */
    private array $calls = [];

    /**
     * @param  array<string, int>  $exitCodes  Exit code per tool; anything unlisted succeeds.
     */
    public function __construct(private readonly array $exitCodes = [], private readonly string $output = 'done') {}

    public function run(string $tool, array $command, string $workingDirectory): ToolResult
    {
        $this->calls[] = ['tool' => $tool, 'command' => $command, 'cwd' => $workingDirectory];

        return new ToolResult($tool, $this->exitCodes[$tool] ?? 0, $this->output);
    }

    /** @return list<array{tool: string, command: list<string>, cwd: string}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @return list<string> */
    public function tools(): array
    {
        return array_map(static fn (array $call): string => $call['tool'], $this->calls);
    }

    /**
     * The arguments one tool was given, for asserting on a flag.
     *
     * @return list<string>
     */
    public function commandFor(string $tool): array
    {
        foreach ($this->calls as $call) {
            if ($call['tool'] === $tool) {
                return $call['command'];
            }
        }

        return [];
    }
}
