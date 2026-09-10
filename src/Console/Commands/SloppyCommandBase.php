<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared plumbing for the sloppy commands: type-safe option reading and report
 * writing.
 */
abstract class SloppyCommandBase extends Command
{
    /**
     * Write a formatted report.
     *
     * Console reports go out a line at a time so long runs stream rather than
     * appearing all at once. JSON goes out raw, because a finding quoting
     * `<p>` from the analysed code must not be mistaken for console markup.
     */
    protected function writeReport(string $report, string $format): void
    {
        if ($format === 'json') {
            $this->output->write($report, false, OutputInterface::OUTPUT_RAW);

            return;
        }

        foreach (explode("\n", rtrim($report, "\n")) as $line) {
            $this->output->writeln($line);
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
}
