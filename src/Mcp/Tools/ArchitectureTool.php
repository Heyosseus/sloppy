<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp\Tools;

use Heyosseus\Sloppy\Architecture\ArchitectureSnapshot;
use Heyosseus\Sloppy\Architecture\DependencyGraph;
use Heyosseus\Sloppy\Ast\ClassSummary;
use Heyosseus\Sloppy\Mcp\McpTool;
use Heyosseus\Sloppy\Mcp\ProjectResolver;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\ArchitectureReport;
use RuntimeException;

/**
 * The project's architecture as Sloppy reads it: every role with its classes,
 * the policies and boundaries in force, one class explained, or the graph.
 *
 * An agent about to change a class asks what that class is and what it may
 * touch, rather than inferring it from the name and being told by SL304.
 */
final readonly class ArchitectureTool implements McpTool
{
    public function __construct(
        private ProjectResolver $projects,
        private ArchitectureReport $report = new ArchitectureReport,
    ) {}

    public function name(): string
    {
        return 'sloppy_architecture';
    }

    public function description(): string
    {
        return 'Show this project\'s architecture: the role each class plays, what each role may depend on and do, and '
            .'which modules may see which. Pass a class to learn its role and the policy it is held to, or graph to see '
            .'which roles depend on which.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => [
                    'type' => 'string',
                    'description' => 'A class to explain, by fully qualified or short name. Omit for the whole architecture.',
                ],
                'graph' => [
                    'type' => 'boolean',
                    'description' => 'Return the role-to-role dependency graph as Mermaid, forbidden edges in red.',
                ],
                'project' => [
                    'type' => 'string',
                    'description' => 'Project root. Defaults to the directory the server was started in.',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['text', 'json'],
                    'description' => 'text (default) reads better; json carries every field.',
                ],
            ],
            'required' => [],
        ];
    }

    public function call(array $arguments): string
    {
        $arguments = new ToolArguments($arguments);
        $snapshot = ArchitectureSnapshot::of($this->projects->resolve($arguments->string('project')));
        $format = $arguments->string('format') === 'json' ? OutputFormat::Json : OutputFormat::Console;
        $class = $arguments->string('class');

        if ($arguments->bool('graph')) {
            return DependencyGraph::of($snapshot)->render($format === OutputFormat::Json ? 'json' : 'mermaid');
        }

        if ($class === null) {
            return $this->report->overview($snapshot, $format);
        }

        $found = $this->report->find($snapshot, $class);

        if (count($found) !== 1) {
            throw new RuntimeException($found === []
                ? sprintf('No class named %s in the analysed paths.', $class)
                : sprintf('%s is ambiguous. Name one of: %s.', $class, implode(', ', array_map(static fn (ClassSummary $summary): string => $summary->fqn, $found))));
        }

        return $this->report->explain($snapshot, $found[0], $format);
    }
}
