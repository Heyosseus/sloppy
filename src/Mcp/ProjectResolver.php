<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp;

use Heyosseus\Sloppy\Cli\ProjectLocator;
use Heyosseus\Sloppy\Configuration\ConfigurationLoader;
use Heyosseus\Sloppy\Sloppy;

/**
 * Which project an MCP call is about.
 *
 * An editor starts one server for a workspace and the agent may then ask about
 * a path inside it, so every tool takes an optional `project` argument and
 * falls back to where the server was started -- the same rule the standalone
 * binary follows, through the same locator, so the two cannot disagree about
 * what "this project" means.
 */
final readonly class ProjectResolver
{
    public function __construct(private string $workingDirectory) {}

    public function resolve(?string $project): Sloppy
    {
        $root = (new ProjectLocator)->locate($project, $this->workingDirectory);

        return new Sloppy((new ConfigurationLoader($root))->load());
    }
}
