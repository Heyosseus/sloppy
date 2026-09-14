<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Help\CommandCatalogue;
use Heyosseus\Sloppy\Help\CommandGroup;
use Heyosseus\Sloppy\Help\CommandSummary;

/**
 * What the commands are, for someone who has just installed this.
 *
 * Alone among the runners this one takes no {@see \Heyosseus\Sloppy\Sloppy}:
 * explaining the commands needs no project, no configuration and no analysis,
 * and the first thing a person runs is often the thing they run before any of
 * those exist.
 */
final readonly class HelpRunner
{
    public function run(RunnerOutput $output): ExitCode
    {
        if ($output->isQuiet()) {
            return ExitCode::Success;
        }

        $output->line('Sloppy finds the patterns that show up when code is written faster than it is read.');
        $output->line('');
        $output->line('Every command below exists twice: once for the standalone binary and once for');
        $output->line('Artisan. Both names run the same code, so pick whichever your project has.');

        foreach (CommandGroup::cases() as $group) {
            $this->printGroup($group, $output);
        }

        $output->line('');
        $output->line('Options for one command: `sloppy help <command>`, or `php artisan sloppy:<command> --help`.');

        return ExitCode::Success;
    }

    private function printGroup(CommandGroup $group, RunnerOutput $output): void
    {
        $output->line('');
        $output->line($group->label());

        foreach (CommandCatalogue::entries() as $summary) {
            if ($summary->group === $group) {
                $this->printSummary($summary, $output);
            }
        }
    }

    private function printSummary(CommandSummary $summary, RunnerOutput $output): void
    {
        $output->line('');
        $output->line(sprintf('  sloppy %s  ·  php artisan %s', $summary->cli, $summary->artisan));
        $output->line(sprintf('    %s', $summary->description));
        $output->line(sprintf('    When: %s', $summary->when));
    }
}
