<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Output\OutputFormat;

/**
 * Everything a runner needs to say, without knowing what is listening.
 *
 * Laravel's console components and Symfony's `SymfonyStyle` render the same
 * four message kinds differently, and the runners must not care which one is
 * on the other end.
 */
interface RunnerOutput
{
    public function error(string $message): void;

    public function info(string $message): void;

    public function warn(string $message): void;

    public function line(string $message): void;

    /**
     * A message for the human that must never enter the report stream.
     *
     * Anything written to stdout alongside a machine-readable report makes that
     * report unparseable, and silently dropping the message instead would hide
     * information the reader needs. Both adapters therefore route this to
     * stderr, where a terminal still shows it and a pipe does not see it.
     */
    public function notice(string $message): void;

    /**
     * Write a finished report.
     *
     * Console reports go out a line at a time so long runs stream; machine
     * formats go out raw, because a finding quoting `<p>` from the analysed
     * code must not be read as console markup.
     */
    public function report(string $report, OutputFormat $format): void;

    public function isQuiet(): bool;

    /**
     * Begin reporting progress over a known number of items.
     *
     * Progress lives on the port rather than behind a returned reporter
     * object: every implementation of that reporter was pure delegation to a
     * `ProgressBar`, which is the shape `SL302` reports, and this package does
     * not get to exempt itself from its own rules.
     */
    public function startProgress(int $total): void;

    public function advanceProgress(): void;

    /**
     * Finish the current progress display, if one was started. Calling this
     * without a matching `startProgress()` is a no-op rather than an error, so
     * a runner does not have to track whether it opened one.
     */
    public function finishProgress(): void;
}
