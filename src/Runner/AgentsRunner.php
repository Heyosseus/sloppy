<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Agent\AgentHost;
use Heyosseus\Sloppy\Agent\HookSettingsFile;
use Heyosseus\Sloppy\Agent\RulesetFormat;
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
        $installed = [];
        $written = [];

        foreach ($options->installHosts() as $host) {
            if (! $this->canInstall($sloppy, $host, $options, $output)) {
                continue;
            }

            // A hook that names a path on this machine works on this machine
            // only, so it never goes in the settings the team shares.
            $local = $options->local || ! $host->hasProjectCopy($sloppy->configuration->basePath);

            if (! $this->installHooks($sloppy, $host, $local, $options, $output)) {
                return ExitCode::Error;
            }

            $installed[] = $host;
            $written[] = $host->settingsFile($local);
        }

        if ($installed === []) {
            $output->error('No agent\'s hooks were installed.');

            return ExitCode::Error;
        }

        if ($options->dryRun) {
            $output->info(sprintf('Dry run: %s were not written.', $this->listed([...$written, ...$this->rulesFiles($sloppy, $installed, $options)])));

            return ExitCode::Success;
        }

        return $this->installRules($sloppy, $installed, $options, $output);
    }

    /**
     * Whether this agent's hooks have somewhere to go. Only Claude Code has
     * a personal settings file inside the project, so the others can take
     * neither `--local` nor a hook that names a path on this machine.
     */
    private function canInstall(Sloppy $sloppy, AgentHost $host, AgentsOptions $options, RunnerOutput $output): bool
    {
        if ($host->hasLocalFile() || (! $options->local && $host->hasProjectCopy($sloppy->configuration->basePath))) {
            return true;
        }

        $output->warn($options->local
            ? sprintf('%s was skipped: it has no personal settings file inside the project, so its hooks cannot be kept out of git. Install without --local to share them with the team.', $host->label())
            : sprintf('%1$s was skipped: this project has no vendor/bin/sloppy for its hooks to run, and %1$s has no personal settings file to hold a path on this machine. Run composer require --dev heyosseus/sloppy and install again.', $host->label()));

        return false;
    }

    /**
     * Merge the hooks into the agent's settings file and write it, or print
     * it on a dry run.
     *
     * @return bool False when the file could not be read or written.
     */
    private function installHooks(Sloppy $sloppy, AgentHost $host, bool $local, AgentsOptions $options, RunnerOutput $output): bool
    {
        $configuration = $sloppy->configuration;
        $relative = $host->settingsFile($local);
        $path = $configuration->absolutePath($relative);
        $existing = is_file($path) ? @file_get_contents($path) : null;

        try {
            $merged = HookSettingsFile::merge(
                $existing === false ? null : $existing,
                $host->command($configuration->basePath, $options->binary, $local),
                $host,
            );
        } catch (InvalidArgumentException $exception) {
            $output->error(sprintf('%s was left alone. %s', $relative, $exception->getMessage()));

            return false;
        }

        if ($local && ! $options->local) {
            $output->warn(sprintf(
                'This project has no vendor/bin/sloppy, so the hooks name %s, which exists on this machine only. They were written to %s instead of the shared %s. Run composer require --dev heyosseus/sloppy and install again to share them with the team.',
                str_replace('\\', '/', $options->binary),
                $relative,
                $host->settingsFile(),
            ));
        }

        if ($options->dryRun) {
            $output->report($merged, OutputFormat::Json);

            return true;
        }

        if ($merged !== $existing && ! $this->write($path, $merged)) {
            $output->error(sprintf('Could not write %s.', $relative));

            return false;
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

        if ($host === AgentHost::Codex) {
            $output->info('Codex runs a project\'s hooks only once you trust them: run /hooks in Codex, from the project root, and trust the two Sloppy hooks.');
        }

        return true;
    }

    /**
     * Write each agent's ruleset beside its hooks, and point out what is left
     * to do.
     *
     * The hooks catch what was written; the ruleset is what stops most of it
     * being written in the first place.
     *
     * @param  list<AgentHost>  $hosts
     */
    private function installRules(Sloppy $sloppy, array $hosts, AgentsOptions $options, RunnerOutput $output): ExitCode
    {
        $rules = new RulesRunner;
        $code = ExitCode::Success;

        if ($options->local && in_array(AgentHost::ClaudeCode, $hosts, true)) {
            $code = $rules->run($sloppy, new RulesOptions(formats: [RulesetFormat::Claude], output: self::LOCAL_RULES_FILE), $output);
            $this->warnIfTracked($sloppy, [AgentHost::ClaudeCode->settingsFile(true), self::LOCAL_RULES_FILE], $output);
        }

        $shared = $this->sharedFormats($hosts, $options, $rules->usesBoost($sloppy->configuration));

        if ($shared !== [] && $rules->run($sloppy, new RulesOptions(formats: $shared), $output) === ExitCode::Error) {
            $code = ExitCode::Error;
        }

        if (in_array(AgentHost::ClaudeCode, $hosts, true) && ! $this->mcpRegistered($sloppy->configuration->absolutePath('.mcp.json'))) {
            $output->info('Optional: register the MCP server as well, so the agent can scan on demand: add "sloppy": {"command": "php", "args": ["vendor/bin/sloppy-mcp"]} under "mcpServers" in .mcp.json.');
        }

        return $code;
    }

    /**
     * The rulesets the team shares, one per agent installed. Boost writes
     * every agent's file from its own guidelines, so where it is installed
     * its guideline stands in for all of them.
     *
     * @param  list<AgentHost>  $hosts
     * @return list<RulesetFormat>
     */
    private function sharedFormats(array $hosts, AgentsOptions $options, bool $usesBoost): array
    {
        $formats = [];

        foreach ($hosts as $host) {
            if ($host === AgentHost::ClaudeCode && $options->local) {
                continue;
            }

            $formats[] = $usesBoost ? RulesetFormat::Boost : ($host->rulesetFormat() ?? RulesetFormat::Claude);
        }

        return array_values(array_unique($formats, SORT_REGULAR));
    }

    /**
     * The ruleset files an install writes, as a dry run names them: the
     * same ones {@see installRules()} would, Boost's guideline included.
     *
     * @param  list<AgentHost>  $hosts
     * @return list<string>
     */
    private function rulesFiles(Sloppy $sloppy, array $hosts, AgentsOptions $options): array
    {
        $local = $options->local && in_array(AgentHost::ClaudeCode, $hosts, true) ? [self::LOCAL_RULES_FILE] : [];
        $shared = $this->sharedFormats($hosts, $options, (new RulesRunner)->usesBoost($sloppy->configuration));

        return [...$local, ...array_map(static fn (RulesetFormat $format): string => $format->defaultFile(), $shared)];
    }

    /**
     * @param  list<string>  $items
     */
    private function listed(array $items): string
    {
        $last = (string) array_pop($items);

        return $items === [] ? $last : implode(', ', $items).' and '.$last;
    }

    /**
     * Take out everything `install` put in: see {@see AgentsUninstaller}.
     */
    public function uninstall(Sloppy $sloppy, AgentsOptions $options, RunnerOutput $output): ExitCode
    {
        return (new AgentsUninstaller)->run($sloppy, $options, $output);
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
