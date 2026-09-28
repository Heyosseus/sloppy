<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Help;

/**
 * Which way in the person used, so a report that names a next step names the
 * one they can type.
 *
 * `vendor/bin/sloppy fix` and `php artisan sloppy:fix` run the same code, but
 * a hint that spells the other surface's name is a hint that fails when
 * pasted.
 */
enum Surface
{
    case Standalone;
    case Artisan;

    /**
     * The full command for a catalogued command, by its standalone name.
     */
    public function command(string $cli, string $arguments = ''): string
    {
        $entry = array_values(array_filter(
            CommandCatalogue::entries(),
            static fn (CommandSummary $summary): bool => $summary->cli === $cli,
        ))[0] ?? null;

        $name = $this === self::Artisan && $entry instanceof CommandSummary
            ? 'php artisan '.$entry->artisan
            : 'vendor/bin/sloppy '.$cli;

        return $arguments === '' ? $name : $name.' '.$arguments;
    }
}
