<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Agent\AgentHost;
use Heyosseus\Sloppy\Agent\ClaudeSettingsFile;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Sloppy;
use InvalidArgumentException;

/**
 * Put Sloppy inside the agent's own loop.
 *
 * The ruleset tells the agent what to avoid and the MCP server lets it check
 * when it thinks to; neither makes it check. Hooks do: the agent's harness
 * runs Sloppy after every edit and before the agent may call the task done,
 * whether or not the model remembered to ask.
 */
final readonly class AgentsRunner
{
    public function run(Sloppy $sloppy, AgentsOptions $options, RunnerOutput $output): ExitCode
    {
        $host = AgentHost::ClaudeCode;
        $configuration = $sloppy->configuration;
        $relative = $host->settingsFile($options->local);
        $path = $configuration->absolutePath($relative);
        $existing = is_file($path) ? @file_get_contents($path) : null;

        try {
            $merged = ClaudeSettingsFile::merge(
                $existing === false ? null : $existing,
                $host->command($configuration->basePath, $options->binary),
            );
        } catch (InvalidArgumentException $exception) {
            $output->error(sprintf('%s was left alone. %s', $relative, $exception->getMessage()));

            return ExitCode::Error;
        }

        if ($options->dryRun) {
            $output->report($merged, OutputFormat::Json);
            $output->info(sprintf('Dry run: %s and CLAUDE.md were not written.', $relative));

            return ExitCode::Success;
        }

        if (! $this->write($path, $merged)) {
            $output->error(sprintf('Could not write %s.', $relative));

            return ExitCode::Error;
        }

        $output->info(sprintf(
            '%s %s: %s now runs Sloppy after every edit, and checks the whole change before it finishes.',
            $existing === null ? 'Created' : 'Updated',
            $relative,
            $host->label(),
        ));

        // The hooks catch what was written; the ruleset is what stops most of
        // it being written in the first place.
        $rules = (new RulesRunner)->run($sloppy, new RulesOptions, $output);

        if (! $this->mcpRegistered($configuration->absolutePath('.mcp.json'))) {
            $output->info('Optional: register the MCP server as well, so the agent can scan on demand. See "MCP server" in the README.');
        }

        return $rules;
    }

    private function write(string $path, string $contents): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            return false;
        }

        return @file_put_contents($path, $contents) !== false;
    }

    private function mcpRegistered(string $path): bool
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;

        return $contents !== false && str_contains($contents, 'sloppy');
    }
}
