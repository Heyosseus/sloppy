<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Mcp;

use SplFileObject;

/**
 * The server's stdio loop: newline-delimited JSON in, the same out.
 *
 * Nothing else may ever be written to standard output while this runs -- a
 * stray warning or a progress bar in the middle of the stream is an
 * unparseable message to the client, and the session ends there. That is why
 * the runners take an output port and why this one writes only what the
 * protocol asked for.
 *
 * The streams are {@see SplFileObject}s so a test can serve a file and read
 * the transcript back, which is the only way to test a loop over stdin.
 */
final readonly class StdioTransport
{
    public function __construct(private McpServer $server) {}

    /**
     * Serve until the input ends, returning how many messages were answered.
     */
    public function serve(SplFileObject $input, SplFileObject $output): int
    {
        $answered = 0;

        while (! $input->eof()) {
            $line = trim($input->fgets());

            if ($line === '') {
                continue;
            }

            $response = $this->server->handleLine($line);

            if ($response === null) {
                continue;
            }

            $output->fwrite($response."\n");
            $answered++;
        }

        return $answered;
    }
}
