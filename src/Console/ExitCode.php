<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console;

/**
 * Process exit codes, kept small and stable so CI can rely on them.
 *
 * The distinction that matters is between "the analyser worked and found
 * problems" (1) and "the analyser could not do its job" (2). A pipeline that
 * conflates the two either ignores real findings or fails on a typo in a config
 * file without saying so.
 */
enum ExitCode: int
{
    /** Analysis completed and nothing breached the configured threshold. */
    case Success = 0;

    /** Analysis completed and found something at or above `fail_on`. */
    case FindingsAboveThreshold = 1;

    /** Analysis could not run: bad configuration, no git repository, unreadable baseline. */
    case Error = 2;
}
