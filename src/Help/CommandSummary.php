<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Help;

/**
 * One command, said twice: the standalone binary's name and Artisan's.
 *
 * Both names live on the same entry because the two surfaces are the same
 * command, and a reader who found this package through one of them should not
 * have to work out that the other exists.
 */
final readonly class CommandSummary
{
    public function __construct(
        public CommandGroup $group,
        public string $cli,
        public string $artisan,
        public string $description,
        public string $when,
    ) {}
}
