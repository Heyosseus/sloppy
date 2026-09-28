<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Fix;

use Heyosseus\Sloppy\Analysis\Finding;

/**
 * Delete the comments SL109 found restating the line below them.
 *
 * This is the one rewrite Sloppy does itself rather than hand to Rector,
 * because no Rector rule knows which comments these are and the answer is
 * already in the finding: a restating comment is reported only when every
 * meaningful word in it is in the code it sits above, so deleting it loses
 * nothing the code does not already say. Step narration ("1. Load the
 * order") is left alone -- it marks a method that wants splitting, and that
 * is a person's job.
 *
 * A line is deleted only when it holds the comment and nothing else, exactly
 * as the finding quoted it. A file edited since the scan, or a comment that
 * shares its line with code, is skipped rather than guessed at.
 */
final readonly class NarrativeCommentRemover
{
    public static function canRemove(Finding $finding): bool
    {
        return $finding->ruleId === 'SL109'
            && ($finding->metrics['kind'] ?? null) === 'restates'
            && is_string($finding->metrics['comment'] ?? null);
    }

    /**
     * @param  list<Finding>  $findings  Any findings; only removable ones are touched.
     * @return list<Finding> The findings whose comment was (or, on a dry run, would be) removed.
     */
    public function remove(string $basePath, array $findings, bool $dryRun = false): array
    {
        $byFile = [];

        foreach ($findings as $finding) {
            if (self::canRemove($finding)) {
                $byFile[$finding->location->relativePath][] = $finding;
            }
        }

        $removed = [];

        foreach ($byFile as $relative => $fileFindings) {
            $removed = [...$removed, ...$this->removeFrom($basePath.'/'.$relative, $fileFindings, $dryRun)];
        }

        return $removed;
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function removeFrom(string $path, array $findings, bool $dryRun): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $lines = preg_split('/(?<=\n)/', $contents) ?: [];
        $removed = [];

        foreach ($findings as $finding) {
            $index = $finding->location->line - 1;

            if (isset($lines[$index]) && $this->holdsOnly($lines[$index], (string) $finding->metrics['comment'])) {
                unset($lines[$index]);
                $removed[] = $finding;
            }
        }

        if ($removed !== [] && ! $dryRun) {
            file_put_contents($path, implode('', $lines));
        }

        return $removed;
    }

    private function holdsOnly(string $line, string $comment): bool
    {
        $trimmed = trim($line);

        return (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#'))
            && trim(ltrim($trimmed, '/#')) === $comment;
    }
}
