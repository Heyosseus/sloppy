<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Cli;

use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\RunnerOutput;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The standalone binary's side of {@see RunnerOutput}.
 */
final class SymfonyRunnerOutput implements RunnerOutput
{
    private ?ProgressBar $bar = null;

    public function __construct(private readonly SymfonyStyle $style) {}

    public function error(string $message): void
    {
        $this->style->writeln('  <fg=red;options=bold>ERROR</> '.$message);
    }

    public function info(string $message): void
    {
        $this->style->writeln('  <fg=green;options=bold>INFO</>  '.$message);
    }

    public function warn(string $message): void
    {
        $this->style->writeln('  <fg=yellow;options=bold>WARN</>  '.$message);
    }

    public function line(string $message): void
    {
        $this->style->writeln($message);
    }

    public function notice(string $message): void
    {
        $this->style->getErrorStyle()->writeln('  <fg=green;options=bold>INFO</>  '.$message);
    }

    public function report(string $report, OutputFormat $format): void
    {
        if ($format->isMachineReadable()) {
            $this->style->write($report, false, OutputInterface::OUTPUT_RAW);

            return;
        }

        foreach (explode("\n", rtrim($report, "\n")) as $line) {
            $this->style->writeln($line);
        }
    }

    public function isQuiet(): bool
    {
        return $this->style->isQuiet();
    }

    public function startProgress(int $total): void
    {
        $this->bar = $this->style->createProgressBar($total);
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
