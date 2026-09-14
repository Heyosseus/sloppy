<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Support\StringListOption;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Type-safe option reading for the Artisan surface.
 *
 * Everything these commands used to do beyond reading options now lives in a
 * runner, so this surface and the standalone binary run the same code and
 * cannot drift apart.
 */
abstract class SloppyCommandBase extends Command
{
    /**
     * The shape every one of these commands shares: read the options, run a
     * runner, and turn a malformed option into exit code 2 with a message
     * rather than a stack trace.
     *
     * It lives here for the same reason {@see \Heyosseus\Sloppy\Cli\CliCommandBase}
     * has its twin: nine commands repeating five lines is nine places for the
     * error path to quietly stop matching.
     *
     * @param  callable(LaravelRunnerOutput): ExitCode  $run
     */
    protected function runWith(callable $run): int
    {
        $output = new LaravelRunnerOutput($this);

        try {
            return $run($output)->value;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return ExitCode::Error->value;
        }
    }

    protected function boolOption(string $name): bool
    {
        return $this->option($name) === true;
    }

    protected function stringOption(string $name, string $default = ''): string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * Null rather than zero when the option was not given, because zero is a
     * meaningful confidence floor and must stay distinguishable from silence.
     */
    protected function intOption(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('--%s must be a number.', $name));
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    protected function stringListOption(string $name): array
    {
        return StringListOption::from($this->option($name));
    }
}
