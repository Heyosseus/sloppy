<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests\Support;

use Heyosseus\Sloppy\Output\OutputFormat;
use Heyosseus\Sloppy\Runner\RunnerOutput;

/**
 * Records what a runner said, so runner tests assert on messages rather than
 * on rendered console output.
 */
final class RecordingRunnerOutput implements RunnerOutput
{
    /** @var list<string> */
    private array $messages = [];

    /** @var list<array{0: string, 1: string}> */
    private array $reports = [];

    /** @var list<string> */
    private array $progressEvents = [];

    /**
     * @param  list<bool>  $answers  What each question asked is answered, in order; no once they run out.
     */
    public function __construct(private readonly bool $quiet = false, private array $answers = []) {}

    public function error(string $message): void
    {
        $this->messages[] = 'error: '.$message;
    }

    public function info(string $message): void
    {
        $this->messages[] = 'info: '.$message;
    }

    public function warn(string $message): void
    {
        $this->messages[] = 'warn: '.$message;
    }

    public function line(string $message): void
    {
        $this->messages[] = 'line: '.$message;
    }

    public function notice(string $message): void
    {
        $this->messages[] = 'notice: '.$message;
    }

    public function report(string $report, OutputFormat $format): void
    {
        $this->reports[] = [$format->value, $report];
    }

    public function isQuiet(): bool
    {
        return $this->quiet;
    }

    public function confirm(string $question): bool
    {
        $this->messages[] = 'confirm: '.$question;

        return array_shift($this->answers) ?? false;
    }

    public function startProgress(int $total): void
    {
        $this->progressEvents[] = 'start:'.$total;
    }

    public function advanceProgress(): void
    {
        $this->progressEvents[] = 'advance';
    }

    public function finishProgress(): void
    {
        $this->progressEvents[] = 'finish';
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }

    /** @return list<string> */
    public function progressEvents(): array
    {
        return $this->progressEvents;
    }

    /** @return list<array{0: string, 1: string}> */
    public function reports(): array
    {
        return $this->reports;
    }

    /**
     * The single report body, for the common case of asserting on one run.
     */
    public function reportBody(): string
    {
        return $this->reports === [] ? '' : $this->reports[0][1];
    }
}
