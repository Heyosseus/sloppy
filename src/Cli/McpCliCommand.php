<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Mcp\McpServer;
use Heyosseus\Sloppy\Mcp\StdioTransport;
use Heyosseus\Sloppy\Runner\ExitCode;
use SplFileObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `sloppy mcp` -- serve the analyser to a coding agent over stdio.
 *
 * Nothing may be printed here but protocol messages, so this command takes no
 * output adapter and writes nothing of its own.
 *
 * The two streams are constructor arguments that default to standard input and
 * output: a test that had to feed the real standard input would block forever
 * on a terminal, and an MCP server nobody can test is an MCP server nobody
 * should ship.
 */
#[AsCommand(name: 'mcp', description: 'Run the Model Context Protocol server so AI agents can check their own code')]
final class McpCliCommand extends CliCommandBase
{
    public function __construct(
        private readonly ?SplFileObject $stdin = null,
        private readonly ?SplFileObject $stdout = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project directory tools default to (default: the working directory)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workingDirectory = $this->stringOption($input, 'project') ?? (string) getcwd();

        (new StdioTransport(McpServer::default($workingDirectory)))->serve(
            $this->stdin ?? new SplFileObject('php://stdin'),
            $this->stdout ?? new SplFileObject('php://stdout', 'w'),
        );

        return ExitCode::Success->value;
    }
}
