<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp\Tools;

use Heyosseus\Sloppy\Integrations\HealthReporter;
use Heyosseus\Sloppy\Mcp\McpTool;
use Heyosseus\Sloppy\Mcp\ProjectResolver;

/**
 * The project's score and what is dragging it down, in one short answer.
 *
 * Cheap by design: it reads the cached snapshot when there is a fresh one, so
 * an agent can ask at the start of a session without paying for an analysis it
 * is about to invalidate anyway.
 */
final readonly class HealthTool implements McpTool
{
    public function __construct(private ProjectResolver $projects) {}

    public function name(): string
    {
        return 'sloppy_health';
    }

    public function description(): string
    {
        return 'Report the project\'s slop score, the count of findings by severity, and the few worth reading '
            .'first. Use it to orient yourself in an unfamiliar codebase before changing anything.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project' => [
                    'type' => 'string',
                    'description' => 'Project root. Defaults to the directory the server was started in.',
                ],
                'fresh' => [
                    'type' => 'boolean',
                    'description' => 'Re-analyse instead of reading the cached snapshot.',
                ],
            ],
            'required' => [],
        ];
    }

    public function call(array $arguments): string
    {
        $arguments = new ToolArguments($arguments);
        $sloppy = $this->projects->resolve($arguments->string('project'));
        $snapshot = (new HealthReporter($sloppy))->current(fresh: $arguments->bool('fresh'));

        $lines = ['# '.$snapshot->summary(), ''];

        foreach ($snapshot->bySeverity as $severity => $count) {
            if ($count > 0) {
                $lines[] = sprintf('- %s: %d', $severity, $count);
            }
        }

        if ($snapshot->top === []) {
            $lines[] = 'Nothing flagged.';

            return implode(PHP_EOL, $lines).PHP_EOL;
        }

        $lines[] = '';
        $lines[] = '## Read first';
        $lines[] = '';

        foreach ($snapshot->top as $finding) {
            $lines[] = sprintf(
                '- `%s` %s at %s:%d (risk %.1f) -- %s',
                $finding['rule'],
                $finding['name'],
                $finding['file'],
                $finding['line'],
                $finding['risk'],
                $finding['message'],
            );
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
