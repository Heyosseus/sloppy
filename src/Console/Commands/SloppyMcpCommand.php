<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Mcp\McpServer;
use Heyosseus\Sloppy\Mcp\StdioTransport;
use Heyosseus\Sloppy\Runner\ExitCode;
use Illuminate\Console\Command;
use SplFileObject;

/**
 * `php artisan sloppy:mcp` -- serve the analyser to a coding agent over stdio.
 *
 * An agent pointed at this can check its own work before it says it is done,
 * inside the application it is editing and with that application's own
 * configuration.
 *
 * Nothing but protocol messages may reach standard output while this runs, so
 * this command writes none of its own. The streams are injectable for the same
 * reason as on the standalone binary: a test cannot feed the real one.
 */
final class SloppyMcpCommand extends Command
{
    protected $signature = 'sloppy:mcp {--project= : Project directory tools default to (default: the application root)}';

    protected $description = 'Run the Model Context Protocol server so AI agents can check their own code';

    public function __construct(
        private readonly ?SplFileObject $stdin = null,
        private readonly ?SplFileObject $stdout = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $project = $this->option('project');
        $root = is_string($project) && trim($project) !== '' ? trim($project) : $this->laravel->basePath();

        (new StdioTransport(McpServer::default($root)))->serve(
            $this->stdin ?? new SplFileObject('php://stdin'),
            $this->stdout ?? new SplFileObject('php://stdout', 'w'),
        );

        return ExitCode::Success->value;
    }
}
