<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Agent\AgentHost;
use Heyosseus\Sloppy\Agent\ClaudeSettingsFile;
use Heyosseus\Sloppy\Agent\McpConfigFile;
use Heyosseus\Sloppy\Agent\RulesetFile;
use Heyosseus\Sloppy\Agent\RulesetFormat;
use Heyosseus\Sloppy\Agent\RulesetGenerator;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Sloppy;
use InvalidArgumentException;

/**
 * Put Sloppy inside the agent's own loop, and take it out again.
 *
 * The ruleset tells the agent what to avoid and the MCP server lets it check
 * when it thinks to; neither makes it check. Hooks do: the agent's harness
 * runs Sloppy after every edit and before the agent may call the task done,
 * whether or not the model remembered to ask.
 */
final readonly class AgentsRunner
{
    /**
     * Where `--local` writes the ruleset: Claude Code reads it beside
     * `CLAUDE.md`, and it is meant to stay out of git.
     */
    public const string LOCAL_RULES_FILE = 'CLAUDE.local.md';

    public function run(Sloppy $sloppy, AgentsOptions $options, RunnerOutput $output): ExitCode
    {
        $host = AgentHost::ClaudeCode;

        // A hook that names a path on this machine works on this machine
        // only, so it never goes in the settings the team shares.
        $local = $options->local || ! $host->hasProjectCopy($sloppy->configuration->basePath);
        $relative = $host->settingsFile($local);

        return $this->installHooks($sloppy, $host, $local, $options, $output)
            ?? $this->installRules($sloppy, $relative, $options, $output);
    }

    /**
     * Merge the hooks into the agent's settings file and write it.
     *
     * @return ExitCode|null The run's answer when it ends here -- an error or
     *                       a dry run -- or null to go on to the ruleset.
     */
    private function installHooks(Sloppy $sloppy, AgentHost $host, bool $local, AgentsOptions $options, RunnerOutput $output): ?ExitCode
    {
        $configuration = $sloppy->configuration;
        $relative = $host->settingsFile($local);
        $path = $configuration->absolutePath($relative);
        $existing = is_file($path) ? @file_get_contents($path) : null;

        try {
            $merged = ClaudeSettingsFile::merge(
                $existing === false ? null : $existing,
                $host->command($configuration->basePath, $options->binary, $local),
            );
        } catch (InvalidArgumentException $exception) {
            $output->error(sprintf('%s was left alone. %s', $relative, $exception->getMessage()));

            return ExitCode::Error;
        }

        if ($local && ! $options->local) {
            $output->warn(sprintf(
                'This project has no vendor/bin/sloppy, so the hooks name %s, which exists on this machine only. They were written to %s instead of the shared .claude/settings.json. Run composer require --dev heyosseus/sloppy and install again to share them with the team.',
                str_replace('\\', '/', $options->binary),
                $relative,
            ));
        }

        if ($options->dryRun) {
            $output->report($merged, OutputFormat::Json);
            $output->info(sprintf('Dry run: %s and %s were not written.', $relative, $options->local ? self::LOCAL_RULES_FILE : 'CLAUDE.md'));

            return ExitCode::Success;
        }

        if ($merged !== $existing && ! $this->write($path, $merged)) {
            $output->error(sprintf('Could not write %s.', $relative));

            return ExitCode::Error;
        }

        $output->info(sprintf(
            '%s %s: %s now runs Sloppy after every edit, and checks the whole change before it finishes.',
            match (true) {
                $existing === null => 'Created',
                $merged === $existing => 'Kept',
                default => 'Updated',
            },
            $relative,
            $host->label(),
        ));

        return null;
    }

    /**
     * Write the ruleset beside the hooks, and point out what is left to do.
     *
     * The hooks catch what was written; the ruleset is what stops most of it
     * being written in the first place.
     */
    private function installRules(Sloppy $sloppy, string $settingsFile, AgentsOptions $options, RunnerOutput $output): ExitCode
    {
        $rules = (new RulesRunner)->run($sloppy, $options->local
            ? new RulesOptions(formats: [RulesetFormat::Claude], output: self::LOCAL_RULES_FILE)
            : new RulesOptions, $output);

        if ($options->local) {
            $this->warnIfTracked($sloppy, [$settingsFile, self::LOCAL_RULES_FILE], $output);
        }

        if (! $this->mcpRegistered($sloppy->configuration->absolutePath('.mcp.json'))) {
            $output->info('Optional: register the MCP server as well, so the agent can scan on demand: add "sloppy": {"command": "php", "args": ["vendor/bin/sloppy-mcp"]} under "mcpServers" in .mcp.json.');
        }

        return $rules;
    }

    /**
     * Take out everything `install` put in: the hooks, the ruleset block (or
     * the Boost guideline, which is Sloppy's own file) and the MCP server
     * entry. Everything else in those files is left byte for byte, and a file
     * left with nothing in it is deleted, since it only existed for Sloppy.
     *
     * `--local` limits it to the personal files.
     */
    public function uninstall(Sloppy $sloppy, AgentsOptions $options, RunnerOutput $output): ExitCode
    {
        $host = AgentHost::ClaudeCode;

        /** @var array<string, callable(string): ?string> $files */
        $files = [$host->settingsFile(true) => ClaudeSettingsFile::remove(...), self::LOCAL_RULES_FILE => RulesetFile::remove(...)];

        if (! $options->local) {
            $files = [
                $host->settingsFile(false) => ClaudeSettingsFile::remove(...),
                ...$files,
                'CLAUDE.md' => RulesetFile::remove(...),
                RulesetFormat::Boost->defaultFile() => static fn (string $contents): ?string => str_starts_with($contents, RulesetGenerator::BOOST_MARKER) ? '' : null,
                '.mcp.json' => McpConfigFile::remove(...),
            ];
        }

        $failed = false;
        $changed = 0;

        foreach ($files as $relative => $remove) {
            $outcome = $this->removeFrom($sloppy->configuration->absolutePath($relative), $relative, $remove, $options->dryRun, $output);
            $failed = $outcome === null || $failed;
            $changed += $outcome === true ? 1 : 0;
        }

        if ($changed === 0 && ! $failed) {
            $output->info('Nothing to remove: no Sloppy hooks, ruleset or MCP server entry was found.');
        } elseif ($options->dryRun) {
            $output->info('Dry run: nothing was written.');
        }

        return $failed ? ExitCode::Error : ExitCode::Success;
    }

    /**
     * @param  callable(string): ?string  $remove
     * @return bool|null Whether the file changed; null when it could not be read or written.
     */
    private function removeFrom(string $path, string $relative, callable $remove, bool $dryRun, RunnerOutput $output): ?bool
    {
        $existing = is_file($path) ? @file_get_contents($path) : null;

        if ($existing === null) {
            return false;
        }

        if ($existing === false) {
            $output->error(sprintf('Could not read %s.', $relative));

            return null;
        }

        try {
            $remaining = $remove($existing);
        } catch (InvalidArgumentException $exception) {
            $output->error(sprintf('%s was left alone. %s', $relative, $exception->getMessage()));

            return null;
        }

        if ($remaining === null) {
            return false;
        }

        $verb = $remaining === '' ? 'Deleted %s: Sloppy\'s entries were all it held.' : 'Removed Sloppy\'s entries from %s.';

        if ($dryRun) {
            $output->info(sprintf('Would have: '.lcfirst($verb), $relative));

            return true;
        }

        $written = $remaining === '' ? @unlink($path) : @file_put_contents($path, $remaining) !== false;

        if (! $written) {
            $output->error(sprintf('Could not write %s.', $relative));

            return null;
        }

        $output->info(sprintf($verb, $relative));

        return true;
    }

    /**
     * A personal file only stays personal if git ignores it. Claude Code sets
     * that up for its own local settings, but not every project has, and a
     * `git add .` would quietly share it.
     *
     * @param  list<string>  $files
     */
    private function warnIfTracked(Sloppy $sloppy, array $files, RunnerOutput $output): void
    {
        $git = $sloppy->git();

        if (! $git->isAvailable() || ! $git->isRepository()) {
            return;
        }

        foreach ($files as $file) {
            if ($git->attempt(['check-ignore', '-q', $file]) === null) {
                $output->warn(sprintf('%s is not ignored by git. Add it to .gitignore so it stays personal.', $file));
            }
        }
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
