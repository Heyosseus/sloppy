<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp\Tools;

use Heyosseus\Sloppy\Architecture\ProfileInference;
use Heyosseus\Sloppy\Architecture\ProfilePrompt;
use Heyosseus\Sloppy\Mcp\McpTool;
use Heyosseus\Sloppy\Mcp\ProjectResolver;
use Heyosseus\Sloppy\Runner\ArchitectureReport;

/**
 * What an agent needs to write `sloppy-architecture.php` from a team's own
 * description of its architecture: the format, the facts in the code and a
 * draft to start from.
 */
final readonly class ArchitecturePromptTool implements McpTool
{
    public function __construct(private ProjectResolver $projects) {}

    public function name(): string
    {
        return 'sloppy_architecture_prompt';
    }

    public function description(): string
    {
        return 'Get everything needed to describe this project\'s architecture for Sloppy: the profile format, what the '
            .'code shows and a draft. Use it to turn an ARCHITECTURE.md or the team\'s description into sloppy-architecture.php.';
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
                'format' => [
                    'type' => 'string',
                    'enum' => ['markdown', 'json'],
                    'description' => 'markdown (default) reads better; json carries every field.',
                ],
            ],
            'required' => [],
        ];
    }

    public function call(array $arguments): string
    {
        $arguments = new ToolArguments($arguments);
        $prompt = new ProfilePrompt(ProfileInference::for($this->projects->resolve($arguments->string('project'))));

        return $arguments->string('format') === 'json' ? (new ArchitectureReport)->json($prompt->toArray()) : $prompt->markdown();
    }
}
