<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Support;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Whether a person is at the other end of this process.
 */
final class Terminal
{
    private function __construct() {}

    /**
     * Interactive input is not enough on its own: Symfony treats a piped
     * standard input as interactive too, and a question read from a pipe is
     * answered by whatever the pipe happens to hold.
     */
    public static function canAsk(InputInterface $input): bool
    {
        return $input->isInteractive() && defined('STDIN') && stream_isatty(STDIN);
    }
}
