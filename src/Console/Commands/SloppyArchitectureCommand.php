<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
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
        {class? : A class to explain, by fully qualified or short name; or graph, place, init, import or prompt}
        {words?* : What place is asked, or the deptrac file import reads}
        {--path=* : Analyse these paths instead of the configured ones}
        {--format= : console or json; mermaid, dot or json for graph}
        {--name= : place: the class name to suggest a namespace and file for}
        {--write : init, import: write sloppy-architecture.php without asking}
        {--force : init, import: replace an existing sloppy-architecture.php}';

    protected $description = 'Show the role each class plays in this project\'s architecture, explain one class, draw the graph, or write a profile';

    public function handle(Sloppy $sloppy): int
    {
        return $this->runWith(function (LaravelRunnerOutput $output) use ($sloppy): ExitCode {
            $class = $this->argument('class');
            $words = $this->argument('words');
            $format = $this->stringOption('format');
            $paths = $this->stringListOption('path');

            return (new ArchitectureRunner)->run($paths === [] ? $sloppy : $sloppy->withConfiguration($sloppy->configuration->withPaths($paths)), ArchitectureOptions::parse(
                subject: is_string($class) ? $class : null,
                words: is_array($words) ? array_values(array_filter($words, is_string(...))) : [],
                format: $format === '' ? null : $format,
                name: $this->stringOption('name'),
                write: $this->boolOption('write'),
                force: $this->boolOption('force'),
            ), $output);
        });
    }
}
