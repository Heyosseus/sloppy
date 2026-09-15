<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Symfony\Component\Console\Application;

/**
 * Sloppy without a framework.
 *
 * The Artisan commands and this application are both thin: each reads options
 * and hands them to the same runner, so the two surfaces cannot drift.
 */
final class SloppyApplication extends Application
{
    public function __construct(string $version = 'dev')
    {
        parent::__construct('sloppy', $version);

        $this->addCommands([
            new ScanCliCommand,
            new DiffCliCommand,
            new ReviewCliCommand,
            new BaselineCliCommand,
            new CiCliCommand,
            new FixCliCommand,
            new HealthCliCommand,
            new WatchCliCommand,
            new RulesCliCommand,
            new McpCliCommand,
            new GuideCliCommand,
        ]);

        // A bare `sloppy` scans, matching `php artisan sloppy`.
        $this->setDefaultCommand('scan');
    }
}
