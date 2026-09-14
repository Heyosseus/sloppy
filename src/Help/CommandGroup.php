<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Help;

/**
 * The three questions someone arrives with.
 *
 * A flat list of ten commands makes the reader compare all ten; grouped by
 * the moment they are useful, the list answers "which one do I want now?"
 * without being read end to end.
 */
enum CommandGroup: string
{
    case Everyday = 'Every day';
    case Pipeline = 'In a pipeline';
    case Agents = 'For agents';

    public function label(): string
    {
        return $this->value;
    }
}
