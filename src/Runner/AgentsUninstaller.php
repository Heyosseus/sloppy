<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Agent\AgentHost;
use Heyosseus\Sloppy\Agent\HookSettingsFile;
use Heyosseus\Sloppy\Agent\McpConfigFile;
use Heyosseus\Sloppy\Agent\RulesetFile;
use Heyosseus\Sloppy\Agent\RulesetFormat;
use Heyosseus\Sloppy\Agent\RulesetGenerator;
use Heyosseus\Sloppy\Sloppy;
use InvalidArgumentException;

/**
 * `sloppy agents uninstall`: what {@see AgentsRunner} put in, taken back out.
 */
final readonly class AgentsUninstaller
{
    /**
     * Take out everything `install` put in: the hooks, the ruleset block (or
     * the Boost guideline, which is Sloppy's own file) and the MCP server
     * entry. Everything else in those files is left byte for byte, and a file
     * left with nothing in it is deleted, since it only existed for Sloppy.
     *
     * `--local` limits it to the personal files, and `--host` to the files of
     * the agents it names.
     */
    public function run(Sloppy $sloppy, AgentsOptions $options, RunnerOutput $output): ExitCode
    {
        $files = [];

        foreach ($options->uninstallHosts() as $host) {
            $files = [...$files, ...$this->installedFiles($host, $options->local)];
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
     * The files an install for this agent may have written, each with what
     * takes Sloppy's part back out of it.
     *
     * @return array<string, callable(string): ?string>
     */
    private function installedFiles(AgentHost $host, bool $local): array
    {
        if ($host === AgentHost::ClaudeCode) {
            $personal = [$host->settingsFile(true) => HookSettingsFile::remove(...), AgentsRunner::LOCAL_RULES_FILE => RulesetFile::remove(...)];

            return $local ? $personal : [
                $host->settingsFile() => HookSettingsFile::remove(...),
                ...$personal,
                'CLAUDE.md' => RulesetFile::remove(...),
                RulesetFormat::Boost->defaultFile() => static fn (string $contents): ?string => str_starts_with($contents, RulesetGenerator::BOOST_MARKER) ? '' : null,
                '.mcp.json' => McpConfigFile::remove(...),
            ];
        }

        if ($local) {
            return [];
        }

        $rules = ($host->rulesetFormat() ?? RulesetFormat::Claude)->defaultFile();

        // Gemini CLI keeps its MCP servers in the same file as its hooks.
        $settings = $host === AgentHost::Gemini
            ? $this->chain(HookSettingsFile::remove(...), McpConfigFile::remove(...))
            : HookSettingsFile::remove(...);

        return [$host->settingsFile() => $settings, $rules => RulesetFile::remove(...)];
    }

    /**
     * Several removals from one file, as one: each reads what the one before
     * it left, and the file changed if any of them changed it.
     *
     * @param  callable(string): ?string  ...$removals
     * @return callable(string): ?string
     */
    private function chain(callable ...$removals): callable
    {
        return static function (string $contents) use ($removals): ?string {
            $changed = false;

            foreach ($removals as $remove) {
                $remaining = $remove($contents);

                if ($remaining === null) {
                    continue;
                }

                $changed = true;
                $contents = $remaining;

                if ($contents === '') {
                    break;
                }
            }

            return $changed ? $contents : null;
        };
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
}
