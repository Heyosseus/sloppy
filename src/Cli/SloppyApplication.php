<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Runner\ExitCode;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
            new ArchitectureCliCommand,
            new AgentsCliCommand,
            new HookCliCommand,
            new McpCliCommand,
            new GuideCliCommand,
        ]);

        // A bare `sloppy` scans, matching `php artisan sloppy`.
        $this->setDefaultCommand('scan');
    }

    /**
     * An option or command that does not exist is a usage error, which this
     * package reports as exit code 2. Symfony's default of 1 would read in CI
     * as "the analysis ran and found something" -- a typo passing for a
     * finding.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (ConsoleException $exception) {
            // Not chained: Symfony renders every previous exception too, and
            // the same message twice reads as two problems.
            throw new RuntimeException($exception->getMessage(), ExitCode::Error->value, $exception);
        }
    }
}
