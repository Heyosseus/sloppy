<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

/**
 * What a hook tells the agent that ran it.
 *
 * Agent hooks speak through exit codes and streams rather than a report:
 * exit 2 with text on standard error is fed back to the model, and anything
 * else lets it carry on. So this is two streams and one decision, not an
 * {@see ExitCode} -- 2 here means "act on this", not "Sloppy broke".
 */
final readonly class HookOutcome
{
    private function __construct(
        private bool $blocking,
        public string $stdout,
        public string $stderr,
    ) {}

    /**
     * Carry on. The notice is for the person reading the transcript, never
     * the model.
     */
    public static function pass(string $notice = ''): self
    {
        return new self(false, $notice, '');
    }

    /**
     * Stop and hand the feedback to the model.
     */
    public static function block(string $feedback): self
    {
        return new self(true, '', $feedback);
    }

    public function blocks(): bool
    {
        return $this->blocking;
    }

    public function exitCode(): int
    {
        return $this->blocking ? 2 : 0;
    }
}
