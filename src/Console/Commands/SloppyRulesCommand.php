<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\RulesOptions;
use Heyosseus\Sloppy\Runner\RulesRunner;
use Heyosseus\Sloppy\Sloppy;

/**
 * `php artisan sloppy:rules` -- write this project's rules where the coding
 * agents will read them before they write anything.
 */
final class SloppyRulesCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy:rules
        {--format=* : claude, cursor, agents, copilot, windsurf, markdown or json (repeatable)}
        {--output= : Write to this file instead of the format\'s usual one}
        {--stdout : Print the ruleset instead of writing a file}
        {--force : Overwrite a generated file that already exists}';

    protected $description = 'Write this project\'s rules into CLAUDE.md, .cursorrules, AGENTS.md and friends';

    public function handle(Sloppy $sloppy): int
    {
        return $this->runWith(function (LaravelRunnerOutput $output) use ($sloppy): ExitCode {
            $target = $this->stringOption('output');

            $options = new RulesOptions(
                formats: RulesOptions::parseFormats($this->stringListOption('format')),
                output: $target === '' ? null : $target,
                stdout: $this->boolOption('stdout'),
                force: $this->boolOption('force'),
            );

            return (new RulesRunner)->run($sloppy, $options, $output);
        });
    }
}
