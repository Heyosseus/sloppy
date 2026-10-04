<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp\Tools;

use Heyosseus\Sloppy\Architecture\ArchitectureSnapshot;
use Heyosseus\Sloppy\Architecture\Placement;
use Heyosseus\Sloppy\Mcp\McpTool;
use Heyosseus\Sloppy\Mcp\ProjectResolver;
use Heyosseus\Sloppy\Runner\ArchitectureReport;
use RuntimeException;

/**
 * Where a new class belongs, asked before the file is created.
 *
 * The cheapest misplaced class is the one never written: an agent that asks
 * "where does an action that refunds an order go?" gets the namespace, the
 * naming and what the class may depend on, in this project's vocabulary.
 */
final readonly class PlaceTool implements McpTool
{
    public function __construct(private ProjectResolver $projects) {}

    public function name(): string
    {
        return 'sloppy_place';
    }

    public function description(): string
    {
        return 'Ask where a new class belongs before you create it. Describe what the class does ("an action that '
            .'refunds an order") and get its role, namespace, file, naming convention and what it may depend on and do.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'description' => [
                    'type' => 'string',
                    'description' => 'What the new class does, in a few words.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'The class name you have in mind, for a suggested namespace and file.',
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
            'required' => ['description'],
        ];
    }

    public function call(array $arguments): string
    {
        $arguments = new ToolArguments($arguments);
        $description = $arguments->string('description');

        if ($description === null) {
            throw new RuntimeException('Say what the class does, e.g. {"description": "an action that refunds an order"}.');
        }

        $placement = new Placement(ArchitectureSnapshot::of($this->projects->resolve($arguments->string('project'))));
        $answer = $placement->answer($description, $arguments->string('name'));

        return $arguments->string('format') === 'json' ? (new ArchitectureReport)->json($answer) : $placement->text($answer);
    }
}
