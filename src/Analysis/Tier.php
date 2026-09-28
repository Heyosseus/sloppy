<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use InvalidArgumentException;

/**
 * What a finding asks of the person reading it.
 *
 * On eight real Laravel applications three quarters of the findings said a
 * method was long, a comment narrated or two bodies matched -- true, and worth
 * knowing, but not a list anyone should work through one line at a time. A
 * swallowed exception or a query in a loop is a thing to go and fix. The tier
 * keeps the two apart: defects are listed, maintainability is summarised per
 * file, and advisory findings are counted.
 *
 * Tiers change how a report is laid out and nothing else. Every finding still
 * counts towards the score, the baseline and `fail_on` exactly as before.
 */
enum Tier: string
{
    case Defect = 'defect';
    case Maintainability = 'maintainability';
    case Advisory = 'advisory';

    public static function parse(string $value): self
    {
        return self::tryFrom(mb_strtolower(trim($value))) ?? throw new InvalidArgumentException(sprintf(
            'Unknown tier [%s]. Expected one of: %s.',
            $value,
            implode(', ', array_column(self::cases(), 'value')),
        ));
    }

    /**
     * The tier a rule gets when its configuration does not name one.
     *
     * By category rather than by rule ID, so a custom rule lands somewhere
     * sensible without anyone having to list it.
     */
    public static function defaultFor(Category $category): self
    {
        return match ($category) {
            Category::ErrorHandling,
            Category::Performance,
            Category::DeadCode,
            Category::Suppression => self::Defect,
            Category::Architecture => self::Advisory,
            Category::Complexity,
            Category::Duplication,
            Category::Readability,
            Category::Dependencies,
            Category::Laravel => self::Maintainability,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Defect => 'Defect',
            self::Maintainability => 'Maintainability',
            self::Advisory => 'Advisory',
        };
    }
}
