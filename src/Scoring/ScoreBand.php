<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Scoring;

/**
 * The human-readable verdict attached to a numeric slop score.
 */
enum ScoreBand: string
{
    case Clean = 'clean';
    case Healthy = 'healthy';
    case NeedsAttention = 'needs_attention';
    case Sloppy = 'sloppy';
    case Severe = 'severe';

    public function label(): string
    {
        return match ($this) {
            self::Clean => 'Clean',
            self::Healthy => 'Healthy',
            self::NeedsAttention => 'Needs attention',
            self::Sloppy => 'Sloppy',
            self::Severe => 'Severe slop',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Clean => 'green',
            self::Healthy => 'green',
            self::NeedsAttention => 'yellow',
            self::Sloppy => 'red',
            self::Severe => 'red',
        };
    }
}
