<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

use InvalidArgumentException;
use JsonException;

/**
 * The part of an agent's hook input that Sloppy reads.
 *
 * Every agent sends a JSON object on standard input for every hook. Most of
 * it -- the session, the transcript, the tool's response -- is none of our
 * business; the edited files, the working directory and whether this stop is
 * already a retry are all we need.
 *
 * Claude Code and Gemini CLI name the one file an edit touched. Codex edits
 * through `apply_patch`, whose input is the patch itself: one edit can add,
 * change and move several files, each named on a `*** ... File:` line,
 * relative to the directory the session runs in.
 */
final readonly class HookPayload
{
    /**
     * @param  list<string>  $filePaths
     */
    public function __construct(
        public array $filePaths = [],
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
        $cwd = self::string($decoded['cwd'] ?? null);
        $filePath = self::string($input['file_path'] ?? null);
        $patch = self::string($input['command'] ?? null);

        return new self(
            filePaths: $filePath !== null ? [$filePath] : self::patchedFiles($patch ?? '', $cwd),
            cwd: $cwd,
            stopHookActive: ($decoded['stop_hook_active'] ?? false) === true,
        );
    }

    /**
     * The files a Codex patch writes: the ones it adds or updates, and a
     * moved file's new name. A deleted file has no code left to judge, and a
     * moved file's old name simply has no findings. Patch paths are relative to the session's directory, so they are
     * made absolute against it, as the other agents send them.
     *
     * @return list<string>
     */
    private static function patchedFiles(string $patch, ?string $cwd): array
    {
        if (preg_match_all('/^\*\*\* (?:(?:Add|Update) File|Move to): (.+)$/m', $patch, $matches) === 0) {
            return [];
        }

        $paths = [];

        foreach ($matches[1] as $path) {
            $path = str_replace('\\', '/', trim($path));
            $isAbsolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;
            $paths[] = $isAbsolute || $cwd === null ? $path : rtrim(str_replace('\\', '/', $cwd), '/').'/'.$path;
        }

        return array_values(array_unique($paths));
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
