<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Output\OutputFormat;
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
#[AsCommand(name: 'architecture', description: 'Show the role each class plays in this project\'s architecture, or explain one class')]
final class ArchitectureCliCommand extends CliCommandBase
{
    protected function configure(): void
    {
        $this->configureSharedOptions();

        $this
            ->addArgument('class', InputArgument::OPTIONAL, 'A class to explain, by fully qualified or short name')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'console or json', 'console');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runWith($input, $output, function (Sloppy $sloppy, RunnerOutput $runnerOutput) use ($input): ExitCode {
            $class = $input->getArgument('class');
            $paths = $this->stringListOption($input, 'path');

            return (new ArchitectureRunner)->run(
                $paths === [] ? $sloppy : $sloppy->withConfiguration($sloppy->configuration->withPaths($paths)),
                new ArchitectureOptions(
                    class: is_string($class) && trim($class) !== '' ? trim($class) : null,
                    format: OutputFormat::parse($this->stringOption($input, 'format') ?? 'console'),
                ),
                $runnerOutput,
            );
        });
    }
}
