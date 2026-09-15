<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Analysis;

/**
 * The kind of problem a rule reports, used for grouping in reports and for
 * letting teams mute whole families of rules.
 */
enum Category: string
{
    case Complexity = 'complexity';
    case Duplication = 'duplication';
    case DeadCode = 'dead-code';
    case ErrorHandling = 'error-handling';
    case Readability = 'readability';
    case Performance = 'performance';
    case Architecture = 'architecture';
    case Dependencies = 'dependencies';
    case Suppression = 'suppression';
    case Laravel = 'laravel';

    public function label(): string
    {
        return match ($this) {
            self::Complexity => 'Complexity',
            self::Duplication => 'Duplication',
            self::DeadCode => 'Dead code',
            self::ErrorHandling => 'Error handling',
            self::Readability => 'Readability',
            self::Performance => 'Performance',
            self::Architecture => 'Architecture',
            self::Dependencies => 'Dependencies',
            self::Suppression => 'Suppression',
            self::Laravel => 'Laravel',
        };
    }
}
