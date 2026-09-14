<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp\Tools;

use Heyosseus\Sloppy\Mcp\McpTool;
use Heyosseus\Sloppy\Mcp\ProjectResolver;
use Heyosseus\Sloppy\Output\DiffJsonFormatter;
use Heyosseus\Sloppy\Output\ReviewFormatter;
use RuntimeException;

/**
 * What the working tree added on top of a revision.
 *
 * This is the tool an agent should reach for before it finishes: it separates
 * the debt the change introduced from the debt it inherited, so the answer is
 * about the work just done rather than about the repository's history.
 */
final readonly class DiffTool implements McpTool
{
    public function __construct(private ProjectResolver $projects) {}

    public function name(): string
    {
        return 'sloppy_diff';
    }

    public function description(): string
    {
        return 'Compare the working tree against a git revision and report only what the change introduced, '
            .'ranked by risk. Use this to check your own work before reporting a task finished: findings listed '
            .'as new are yours, findings listed as inherited are not.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'base' => [
                    'type' => 'string',
                    'description' => 'Revision to compare against, e.g. main, origin/main or HEAD~1. Defaults to HEAD.',
                ],
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
        $sloppy = $this->projects->resolve($arguments->string('project'));
        $base = $arguments->string('base') ?? 'HEAD';
        $git = $sloppy->git();

        if (! $git->isAvailable() || ! $git->isRepository()) {
            throw new RuntimeException(sprintf('%s is not a git repository, so there is nothing to compare against.', $sloppy->configuration->basePath));
        }

        if (! $git->revisionExists($base)) {
            throw new RuntimeException(sprintf('Revision [%s] could not be resolved in this repository.', $base));
        }

        $report = $sloppy->diff($base);

        if ($arguments->string('format') === 'json') {
            return (new DiffJsonFormatter)->format($report);
        }

        return (new ReviewFormatter(risk: $sloppy->risks(), markdown: true))->format($report);
    }
}
