<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Runner;

/**
 * What `sloppy agents install` was asked to do.
 */
final readonly class AgentsOptions
{
    /**
     * @param  bool  $local  Write the personal, uncommitted settings file instead of the shared one.
     * @param  bool  $dryRun  Print the settings that would be written, and write nothing.
     * @param  string  $binary  The Sloppy binary running now, for projects that have no copy of their own to point the hooks at.
     */
    public function __construct(
        public bool $local = false,
        public bool $dryRun = false,
        public string $binary = '',
    ) {}
}
