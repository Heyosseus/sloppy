<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Runner\ArchitectureOptions;
use Heyosseus\Sloppy\Runner\ArchitectureRunner;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\RunnerOutput;
use Heyosseus\Sloppy\Sloppy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `sloppy architecture` -- which role every class plays, and why.
 */
#[AsCommand(name: 'architecture', description: 'Show the role each class plays in this project\'s architecture, explain one class, draw the graph, or write a profile')]
final class ArchitectureCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this->configureSharedOptions(filters: false);

        $this
            ->addArgument('class', InputArgument::OPTIONAL, 'A class to explain, by fully qualified or short name; or graph, place, init, import or prompt')
            ->addArgument('words', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'What place is asked, or the deptrac file import reads')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'console or json; mermaid, dot or json for graph')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'place: the class name to suggest a namespace and file for')
            ->addOption('write', null, InputOption::VALUE_NONE, 'init, import: write sloppy-architecture.php without asking')
            ->addOption('force', null, InputOption::VALUE_NONE, 'init, import: replace an existing sloppy-architecture.php');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runWith($input, $output, function (Sloppy $sloppy, RunnerOutput $runnerOutput) use ($input): ExitCode {
            $class = $input->getArgument('class');
            $words = $input->getArgument('words');
            $paths = $this->stringListOption($input, 'path');

            return (new ArchitectureRunner)->run(
                $paths === [] ? $sloppy : $sloppy->withConfiguration($sloppy->configuration->withPaths($paths)),
                ArchitectureOptions::parse(
                    subject: is_string($class) ? $class : null,
                    words: is_array($words) ? array_values(array_filter($words, is_string(...))) : [],
                    format: $this->stringOption($input, 'format'),
                    name: $this->stringOption($input, 'name'),
                    write: $input->getOption('write') === true,
                    force: $input->getOption('force') === true,
                ),
                $runnerOutput,
            );
        });
    }
}
