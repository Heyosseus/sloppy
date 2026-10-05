<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Closure;

/**
 * Gives the terminal back when the watch loop is stopped from outside it.
 *
 * `q` ends the loop and the runner's `finally` restores the terminal. Ctrl+C
 * does not end the loop: on Unix it kills the process, `finally` never runs,
 * and the shell is left with echo off, line editing off and no cursor. So
 * while the dashboard is open, SIGINT, SIGTERM and SIGHUP -- and on Windows
 * the console's Ctrl+C and close events -- restore first and exit after, with
 * the conventional 128 + signal code. A shutdown function covers every other
 * way out: `exit()` from anywhere, or a fatal error.
 *
 * Signals need the pcntl extension. Without it nothing is registered and
 * Ctrl+C behaves as it always did; the shutdown function still runs.
 */
final class InterruptHandler
{
    private const int SIGINT = 2;

    /** @var (Closure(): void)|null */
    private ?Closure $restore = null;

    /** @var array<int, mixed> Signal number => the handler that was there before. */
    private array $previous = [];

    private bool $previousAsync = false;

    private bool $shutdownRegistered = false;

    /** @var (Closure(int): void)|null The console handler, kept so the same one can be taken off again. */
    private ?Closure $windowsHandler = null;

    /** @var Closure(int): void */
    private readonly Closure $exit;

    /**
     * @param  (callable(int): void)|null  $exit  How to end the process; injectable so a test can survive an interrupt.
     */
    public function __construct(?callable $exit = null)
    {
        $this->exit = $exit === null ? static function (int $code): never {
            exit($code);
        } : $exit(...);
    }

    /**
     * @param  Closure(): void  $restore  Puts the terminal back; called at most once per install.
     */
    public function install(Closure $restore): void
    {
        $this->restore = $restore;

        if (! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function($this->restore(...));
        }

        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            $this->previousAsync = pcntl_async_signals(true);

            foreach ($this->signals() as $signal) {
                $this->previous[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, $this->interrupt(...));
            }
        }

        if (function_exists('sapi_windows_set_ctrl_handler') && PHP_SAPI === 'cli') {
            $this->windowsHandler = $this->windowsEvent(...);
            @sapi_windows_set_ctrl_handler($this->windowsHandler, true);
        }
    }

    /**
     * The terminal is back in the caller's hands: put the handlers back too.
     */
    public function uninstall(): void
    {
        $this->restore = null;

        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            foreach ($this->previous as $signal => $handler) {
                pcntl_signal($signal, is_callable($handler) || is_int($handler) ? $handler : SIG_DFL);
            }

            pcntl_async_signals($this->previousAsync);
        }

        $this->previous = [];

        if ($this->windowsHandler instanceof Closure && function_exists('sapi_windows_set_ctrl_handler')) {
            @sapi_windows_set_ctrl_handler($this->windowsHandler, false);
            $this->windowsHandler = null;
        }
    }

    /**
     * Restore, then stop the way the signal asked.
     */
    public function interrupt(int $signal): void
    {
        $this->restore();

        ($this->exit)(128 + $signal);
    }

    /**
     * Put the terminal back, once, if it is still ours to put back.
     */
    public function restore(): void
    {
        $restore = $this->restore;
        $this->restore = null;

        if ($restore instanceof Closure) {
            $restore();
        }
    }

    private function windowsEvent(int $event): void
    {
        $this->interrupt(self::SIGINT);
    }

    /**
     * @return list<int>
     */
    private function signals(): array
    {
        return array_values(array_filter(
            [defined('SIGINT') ? SIGINT : null, defined('SIGTERM') ? SIGTERM : null, defined('SIGHUP') ? SIGHUP : null],
            is_int(...),
        ));
    }
}
