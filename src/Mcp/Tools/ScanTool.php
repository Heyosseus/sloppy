<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp\Tools;

use Heyosseus\Sloppy\Mcp\McpTool;
use Heyosseus\Sloppy\Mcp\ProjectResolver;
use Heyosseus\Sloppy\Output\JsonFormatter;
use Heyosseus\Sloppy\Output\MarkdownFormatter;
use Heyosseus\Sloppy\Sloppy;

/**
 * Analyse a project, or a few files of one.
 *
 * The argument that matters is `paths`: an agent that has just written
 * `app/Services/Billing.php` should ask about that file, get an answer in a
 * second, and fix what it hears before it says it is done.
 */
final readonly class ScanTool implements McpTool
{
    public function __construct(private ProjectResolver $projects) {}

    public function name(): string
    {
        return 'sloppy_scan';
    }

    public function description(): string
    {
        return 'Analyse PHP files for AI-slop patterns: god methods, duplicated logic, swallowed exceptions, '
            .'needless abstraction, N+1 risks and more. Call it on the files you just wrote, before reporting '
            .'the task finished, and fix what it reports.';
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
                'paths' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Files or directories to analyse, relative to the project root. Defaults to the configured paths.',
                ],
                'rules' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Only run these rule IDs, e.g. ["SL101", "SL107"].',
                ],
                'min_confidence' => [
                    'type' => 'integer',
                    'description' => 'Drop findings below this confidence (0-100).',
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
        $sloppy = $this->configured($arguments);
        $result = $sloppy->analyze();

        if ($arguments->string('format') === 'json') {
            return (new JsonFormatter(risk: $sloppy->risks()))->format($result);
        }

        return (new MarkdownFormatter(risk: $sloppy->risks()))->format($result);
    }

    private function configured(ToolArguments $arguments): Sloppy
    {
        $sloppy = $this->projects->resolve($arguments->string('project'));
        $configuration = $sloppy->configuration;
        $paths = $arguments->strings('paths');

        if ($paths !== []) {
            $configuration = $configuration->withPaths($paths);
        }

        $confidence = $arguments->int('min_confidence');

        if ($confidence !== null) {
            $configuration = $configuration->withMinConfidence(max(0, min(100, $confidence)));
        }

        $sloppy = $sloppy->withConfiguration($configuration);
        $rules = $arguments->strings('rules');

        return $rules === [] ? $sloppy : $sloppy->onlyRules($rules);
    }
}
