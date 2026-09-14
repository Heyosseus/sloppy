<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Heyosseus\Sloppy\Console\LaravelRunnerOutput;
use Heyosseus\Sloppy\Runner\ExitCode;
use Heyosseus\Sloppy\Runner\HelpRunner;

/**
 * `php artisan sloppy:help` -- what the commands are and when to reach for
 * each.
 *
 * `php artisan list sloppy` already prints the one-line descriptions. What
 * this adds is the moment each command belongs to, and the standalone
 * binary's name for it, which an application developer has no other way to
 * discover.
 */
final class SloppyHelpCommand extends SloppyCommandBase
{
    protected $signature = 'sloppy:help';

    protected $description = 'Explain what each Sloppy command is for';

    public function handle(): int
    {
        return $this->runWith(fn (LaravelRunnerOutput $output): ExitCode => (new HelpRunner)->run($output));
    }
}
