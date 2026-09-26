<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

use InvalidArgumentException;
use JsonException;

/**
 * The part of an agent's hook input that Sloppy reads.
 *
 * Claude Code sends a JSON object on standard input for every hook. Most of it
 * -- the session, the transcript, the tool's response -- is none of our
 * business; the edited file, the working directory and whether this Stop is
 * already a retry are all we need.
 */
final readonly class HookPayload
{
    public function __construct(
        public ?string $filePath = null,
        public ?string $cwd = null,
        public bool $stopHookActive = false,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode(trim($json) === '' ? '{}' : $json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The hook payload is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('The hook payload is not a JSON object.');
        }

        $input = is_array($decoded['tool_input'] ?? null) ? $decoded['tool_input'] : [];

        return new self(
            filePath: self::string($input['file_path'] ?? null),
            cwd: self::string($decoded['cwd'] ?? null),
            stopHookActive: ($decoded['stop_hook_active'] ?? false) === true,
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
