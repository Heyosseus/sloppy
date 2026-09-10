<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

use InvalidArgumentException;

/**
 * How much a finding matters if it turns out to be real.
 *
 * Severity is orthogonal to confidence: a swallowed exception is severe even
 * when we are only somewhat sure we found one, and a narrative comment is
 * trivial even when we are certain.
 */
enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    /**
     * Parse a severity from user configuration, where casing is not guaranteed.
     */
    public static function parse(string $value): self
    {
        $severity = self::tryFrom(mb_strtolower(trim($value)));

        if (! $severity instanceof self) {
            throw new InvalidArgumentException(sprintf(
                'Unknown severity [%s]. Expected one of: %s.',
                $value,
                implode(', ', array_column(self::cases(), 'value')),
            ));
        }

        return $severity;
    }

    public function label(): string
    {
        return mb_strtoupper($this->value);
    }

    /**
     * Ordering rank, highest severity first. Used for sorting and thresholds.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
            self::Info => 4,
        };
    }

    /**
     * Default scoring penalty contributed by one finding at this severity.
     *
     * Overridable through `sloppy.score.weights`.
     */
    public function defaultWeight(): float
    {
        return match ($this) {
            self::Critical => 20.0,
            self::High => 10.0,
            self::Medium => 4.0,
            self::Low => 1.5,
            self::Info => 0.5,
        };
    }

    /**
     * Whether this severity is at least as serious as the given threshold.
     */
    public function isAtLeast(self $threshold): bool
    {
        return $this->rank() <= $threshold->rank();
    }

    /**
     * Symfony console colour used when rendering the severity tag.
     */
    public function color(): string
    {
        return match ($this) {
            self::Critical => 'red',
            self::High => 'red',
            self::Medium => 'yellow',
            self::Low => 'cyan',
            self::Info => 'gray',
        };
    }
}
