<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console;

use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\RunnerOutput;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The Artisan side of {@see RunnerOutput}.
 *
 * It keeps using Laravel's console components so the output of
 * `php artisan sloppy` is unchanged by the move to runners.
 */
final class LaravelRunnerOutput implements RunnerOutput
{
    private ?ProgressBar $bar = null;

    public function __construct(private readonly Command $command) {}

    public function error(string $message): void
    {
        $this->command->outputComponents()->error($message);
    }

    public function info(string $message): void
    {
        $this->command->outputComponents()->info($message);
    }

    public function warn(string $message): void
    {
        $this->command->outputComponents()->warn($message);
    }

    public function line(string $message): void
    {
        $this->command->getOutput()->writeln($message);
    }

    /**
     * Laravel has no `outputComponents()->info()` equivalent for stderr: the
     * factory Artisan builds is bound to stdout at construction. This builds
     * the same kind of factory over the error output instead, so the "INFO"
     * component renders identically there.
     *
     * The input given to that `OutputStyle` is never used: it exists only to
     * satisfy the constructor, since a notice never asks a question.
     */
    public function notice(string $message): void
    {
        $raw = $this->command->getOutput()->getOutput();
        $errorOutput = $raw instanceof ConsoleOutputInterface ? $raw->getErrorOutput() : $raw;

        (new Factory(new OutputStyle(new ArrayInput([]), $errorOutput)))->info($message);
    }

    public function report(string $report, OutputFormat $format): void
    {
        $output = $this->command->getOutput();

        if ($format->isMachineReadable()) {
            $output->write($report, false, OutputInterface::OUTPUT_RAW);

            return;
        }

        foreach (explode("\n", rtrim($report, "\n")) as $line) {
            $output->writeln($line);
        }
    }

    public function isQuiet(): bool
    {
        return $this->command->getOutput()->isQuiet();
    }

    public function startProgress(int $total): void
    {
        $this->bar = $this->command->getOutput()->createProgressBar($total);
        $this->bar->start();
    }

    public function advanceProgress(): void
    {
        $this->bar?->advance();
    }

    public function finishProgress(): void
    {
        $this->bar?->finish();
        $this->bar = null;
    }
}
