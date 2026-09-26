<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Output;

use Heyosseus\Sloppy\Analysis\Finding;
use Heyosseus\Sloppy\Analysis\Severity;

/**
 * Findings written for the model that just produced them.
 *
 * The reader is an agent in the middle of a task, so this is plain text with
 * no colour, one finding per line and the fix beside it. It is capped: a
 * large change that trips twenty rules should not spend the agent's context
 * on the eleventh through twentieth when fixing the first ten is the job.
 */
final readonly class HookFeedbackFormatter
{
    public const int LIMIT = 10;

    /**
     * @param  list<Finding>  $findings
     */
    public function forEdit(string $relativePath, array $findings): string
    {
        return sprintf(
            "Sloppy: this edit to %s introduced %s.\n\n%s\nFix these while the code is in front of you. If one is deliberate, say why in your reply rather than in a code comment.\n",
            $relativePath,
            $this->count($findings),
            $this->list($findings),
        );
    }

    /**
     * @param  list<Finding>  $findings
     */
    public function forStop(array $findings, Severity $threshold): string
    {
        return sprintf(
            "Sloppy: before you finish, this change introduced %s at or above %s.\n\n%s\nFix them, then finish. If one is deliberate, say why in your reply; you will not be stopped a second time.\n",
            $this->count($findings),
            $threshold->value,
            $this->list($findings),
        );
    }

    /**
     * @param  list<Finding>  $findings
     */
    public function leftovers(array $findings): string
    {
        return sprintf("Sloppy: finishing with %s still new in this change.\n\n%s", $this->count($findings), $this->list($findings));
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function list(array $findings): string
    {
        $lines = '';

        foreach (array_slice($findings, 0, self::LIMIT) as $finding) {
            $lines .= sprintf(
                "- %s:%d %s %s (%s): %s\n  Fix: %s\n",
                $finding->location->relativePath,
                $finding->location->line,
                $finding->ruleId,
                $finding->ruleName,
                $finding->severity->value,
                $finding->message,
                $finding->suggestion,
            );
        }

        $hidden = count($findings) - self::LIMIT;

        if ($hidden > 0) {
            $lines .= sprintf("- ...and %d more. Run `sloppy diff` for the full list.\n", $hidden);
        }

        return $lines;
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function count(array $findings): string
    {
        return count($findings) === 1 ? '1 finding' : count($findings).' findings';
    }
}
