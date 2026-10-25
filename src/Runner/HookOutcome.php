<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

use Heyosseus\Sloppy\Agent\AgentHost;
use Heyosseus\Sloppy\Agent\HookEvent;

/**
 * What a hook tells the agent that ran it.
 *
 * A block is written as the agent's JSON decision on standard output --
 * `{"decision": "block", "reason": "..."}`, or what the host takes instead:
 * see {@see AgentHost::blockOutput()} -- and the hook exits 0. The other
 * way to block, exit 2 with the feedback on standard error, depends on the
 * exit code surviving the shell the hook runs in, and on Windows without Git
 * Bash Claude Code runs hooks through PowerShell, which reports a native
 * command's exit 2 as a plain failure. The block then turned into a warning
 * nobody read. JSON on standard output survives any shell.
 *
 * A pass is a notice for the person reading the transcript, never the model:
 * plain text on standard output for Claude Code, and on standard error for
 * the agents that read standard output as JSON and nothing else.
 */
final readonly class HookOutcome
{
    public string $stdout;

    public string $stderr;

    private function __construct(
        private bool $blocking,
        string $notice,
        public string $feedback,
        AgentHost $host,
        HookEvent $event,
    ) {
        $visible = $blocking || $host->noticesOnStdout();
        $this->stdout = match (true) {
            $blocking => $this->decision($host->blockOutput($event, rtrim($feedback))),
            $visible => $notice,
            default => '',
        };
        $this->stderr = $visible ? '' : $notice;
    }

    /**
     * Carry on. The notice is for the person reading the transcript, never
     * the model.
     */
    public static function pass(string $notice = '', AgentHost $host = AgentHost::ClaudeCode): self
    {
        return new self(false, $notice, '', $host, HookEvent::Stop);
    }

    /**
     * Stop and hand the feedback to the model.
     */
    public static function block(string $feedback, AgentHost $host = AgentHost::ClaudeCode, HookEvent $event = HookEvent::Stop): self
    {
        return new self(true, '', $feedback, $host, $event);
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

    /**
     * @param  array<string, mixed>  $output
     */
    private function decision(array $output): string
    {
        return json_encode(
            $output,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }
}
