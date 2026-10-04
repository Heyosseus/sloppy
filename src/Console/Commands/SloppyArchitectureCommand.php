<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\ArchitectureOptions;
use Heyosseus\Sloppy\Runner\ArchitectureRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Sloppy;

/**
 * `php artisan sloppy:architecture` -- which role every class plays, and why.
 */
final class SloppyArchitectureCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy:architecture
        {class? : A class to explain, by fully qualified or short name}
        {--format=console : console or json}';

    protected $description = 'Show the role each class plays in this project\'s architecture, or explain one class';

    public function handle(Sloppy $sloppy): int
    {
        return $this->runWith(function (LaravelRunnerOutput $output) use ($sloppy): ExitCode {
            $class = $this->argument('class');

            return (new ArchitectureRunner)->run($sloppy, new ArchitectureOptions(
                class: is_string($class) && trim($class) !== '' ? trim($class) : null,
                format: OutputFormat::parse($this->stringOption('format', 'console')),
            ), $output);
        });
    }
}
