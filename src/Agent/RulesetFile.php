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
        // The team's own line endings, so the block does not turn a LF file
        // into a mixed one on Windows; a new file gets the platform's.
        $eol = $existing === null || trim($existing) === '' ? PHP_EOL : (str_contains($existing, "\r\n") ? "\r\n" : "\n");
        $body = preg_replace('/\R/', $eol, trim($generated)) ?? trim($generated);
        $block = self::BEGIN.$eol.$eol.$body.$eol.$eol.self::END.$eol;

        if ($existing === null || trim($existing) === '') {
            return $block;
        }

        $start = mb_strpos($existing, self::BEGIN);
        $end = mb_strpos($existing, self::END);

        if ($start === false || $end === false || $end < $start) {
            return rtrim($existing).$eol.$eol.$block;
        }

        return mb_substr($existing, 0, $start)
            .$block
            .ltrim(mb_substr($existing, $end + mb_strlen(self::END)));
    }

    /**
     * The file with our block taken out, and the blank lines `merge()` put
     * between it and the team's own text.
     *
     * @return string|null The new contents; '' when the block was all there was, null when there was no block.
     */
    public static function remove(string $existing): ?string
    {
        $start = mb_strpos($existing, self::BEGIN);
        $end = mb_strpos($existing, self::END);

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $before = mb_substr($existing, 0, $start);
        $after = ltrim(mb_substr($existing, $end + mb_strlen(self::END)), "\r\n");

        if (trim($before) === '' && trim($after) === '') {
            return '';
        }

        if (trim($before) === '') {
            return $after;
        }

        $eol = str_contains(rtrim($before).$after, "\r\n") ? "\r\n" : "\n";

        return $after === ''
            ? rtrim($before).$eol
            : rtrim($before).$eol.$eol.$after;
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
