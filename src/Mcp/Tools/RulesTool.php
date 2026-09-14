<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp\Tools;

use Heyosseus\Sloppy\Agent\RuleGuidance;
use Heyosseus\Sloppy\Agent\RulesetFormat;
use Heyosseus\Sloppy\Agent\RulesetGenerator;
use Heyosseus\Sloppy\Contracts\Rule;
use Heyosseus\Sloppy\Mcp\McpTool;
use Heyosseus\Sloppy\Mcp\ProjectResolver;
use RuntimeException;

/**
 * The rules this project enforces, and one rule in detail.
 *
 * Asked before writing rather than after, this is the cheapest call in the
 * server: a model that knows the project fails builds on swallowed exceptions
 * does not write one.
 */
final readonly class RulesTool implements McpTool
{
    public function __construct(private ProjectResolver $projects) {}

    public function name(): string
    {
        return 'sloppy_rules';
    }

    public function description(): string
    {
        return 'List the code rules this project enforces, with what each one flags and what to write instead. '
            .'Read this before writing code in an unfamiliar repository. Pass a rule ID for one rule in full.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rule' => [
                    'type' => 'string',
                    'description' => 'A single rule ID, e.g. SL101. Omit for every rule in force.',
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
        $format = $arguments->string('format') === 'json' ? RulesetFormat::Json : RulesetFormat::Markdown;
        $id = $arguments->string('rule');

        if ($id === null) {
            return RulesetGenerator::for($sloppy)->generate($format);
        }

        $rule = $sloppy->rules()->get(mb_strtoupper($id));

        if (! $rule instanceof Rule) {
            throw new RuntimeException(sprintf(
                'No rule [%s] is in force here. Enabled rules: %s.',
                mb_strtoupper($id),
                implode(', ', $sloppy->rules()->ids()),
            ));
        }

        return $this->describe($rule);
    }

    private function describe(Rule $rule): string
    {
        return implode(PHP_EOL, [
            sprintf('# %s %s', $rule->id(), $rule->name()),
            '',
            sprintf('- Category: %s, severity: %s', $rule->category()->label(), $rule->severity()->value),
            sprintf('- Flags: %s', $rule->description()),
            sprintf('- Why it costs: %s', $rule->explanation()),
            sprintf('- Write it this way instead: %s', RuleGuidance::for($rule)),
        ]).PHP_EOL;
    }
}
