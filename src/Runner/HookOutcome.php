<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

/**
 * What a hook tells the agent that ran it.
 *
 * A block is written as Claude Code's JSON decision on standard output --
 * `{"decision": "block", "reason": "..."}` -- and the hook exits 0. The other
 * way to block, exit 2 with the feedback on standard error, depends on the
 * exit code surviving the shell the hook runs in, and on Windows without Git
 * Bash Claude Code runs hooks through PowerShell, which reports a native
 * command's exit 2 as a plain failure. The block then turned into a warning
 * nobody read. JSON on standard output survives any shell.
 *
 * A pass is plain text on standard output: a notice for the person reading
 * the transcript, never the model.
 */
final readonly class HookOutcome
{
    public string $stdout;

    public string $stderr;

    private function __construct(
        private bool $blocking,
        string $notice,
        public string $feedback,
    ) {
        $this->stdout = $blocking ? self::decision($feedback) : $notice;
        $this->stderr = '';
    }

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

    /**
     * Always 0: the decision travels in the JSON, not the exit code.
     */
    public function exitCode(): int
    {
        return 0;
    }

    private static function decision(string $feedback): string
    {
        return json_encode(
            ['decision' => 'block', 'reason' => rtrim($feedback)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }
}
