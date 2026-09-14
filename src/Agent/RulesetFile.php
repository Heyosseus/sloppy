<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

/**
 * Writing into a file that belongs to someone else.
 *
 * `CLAUDE.md` and `AGENTS.md` are usually already there and usually full of
 * instructions that have nothing to do with this package. Overwriting them
 * would be the last thing this command ever did in that repository, so the
 * generated rules go inside a marked block: appended the first time, replaced
 * in place afterwards, and everything outside the markers left exactly as the
 * team wrote it.
 */
final readonly class RulesetFile
{
    public const string BEGIN = '<!-- sloppy:begin -->';

    public const string END = '<!-- sloppy:end -->';

    /**
     * The new contents of the file: the existing text with our block updated,
     * or created.
     */
    public static function merge(?string $existing, string $generated): string
    {
        $block = self::BEGIN.PHP_EOL.PHP_EOL.trim($generated).PHP_EOL.PHP_EOL.self::END.PHP_EOL;

        if ($existing === null || trim($existing) === '') {
            return $block;
        }

        $start = mb_strpos($existing, self::BEGIN);
        $end = mb_strpos($existing, self::END);

        if ($start === false || $end === false || $end < $start) {
            return rtrim($existing).PHP_EOL.PHP_EOL.$block;
        }

        return mb_substr($existing, 0, $start)
            .$block
            .ltrim(mb_substr($existing, $end + mb_strlen(self::END)));
    }

    /**
     * Whether a file already carries a generated block, which is the
     * difference between "update" and "append" in what the command reports.
     */
    public static function hasBlock(string $contents): bool
    {
        return str_contains($contents, self::BEGIN) && str_contains($contents, self::END);
    }
}
