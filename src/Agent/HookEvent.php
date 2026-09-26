<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Agent;

use InvalidArgumentException;

/**
 * The two moments an agent's own hooks hand its work to Sloppy.
 *
 * After an edit is the cheapest moment to fix a finding: the agent still has
 * the code in hand. Before it reports the task finished is the last moment:
 * after that, a person reads the diff.
 */
enum HookEvent: string
{
    case PostEdit = 'post-edit';
    case Stop = 'stop';

    public static function parse(string $value): self
    {
        $event = self::tryFrom(mb_strtolower(trim($value)));

        if (! $event instanceof self) {
            throw new InvalidArgumentException(sprintf(
                'Unknown hook event [%s]. Expected one of: %s.',
                trim($value),
                implode(', ', array_column(self::cases(), 'value')),
            ));
        }

        return $event;
    }
}
