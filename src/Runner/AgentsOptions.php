<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Agent\AgentHost;

/**
 * What `sloppy agents install` was asked to do.
 */
final readonly class AgentsOptions
{
    /**
     * @param  bool  $local  Write the personal, uncommitted settings file instead of the shared one.
     * @param  bool  $dryRun  Print the settings that would be written, and write nothing.
     * @param  string  $binary  The Sloppy binary running now, for projects that have no copy of their own to point the hooks at.
     * @param  list<AgentHost>  $hosts  The agents to wire up; empty means Claude Code on install, and every agent on uninstall.
     */
    public function __construct(
        public bool $local = false,
        public bool $dryRun = false,
        public string $binary = '',
        public array $hosts = [],
    ) {}

    /**
     * @return list<AgentHost>
     */
    public function installHosts(): array
    {
        return $this->hosts === [] ? [AgentHost::ClaudeCode] : $this->hosts;
    }

    /**
     * @return list<AgentHost>
     */
    public function uninstallHosts(): array
    {
        return $this->hosts === [] ? AgentHost::cases() : $this->hosts;
    }

    /**
     * @param  list<string>  $values
     * @return list<AgentHost>
     */
    public static function parseHosts(array $values): array
    {
        return array_values(array_unique(array_map(AgentHost::parse(...), $values), SORT_REGULAR));
    }
}
