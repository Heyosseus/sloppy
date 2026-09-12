<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Type-safe option reading for the Artisan surface.
 *
 * Everything these commands used to do beyond reading options now lives in a
 * runner, so this surface and the standalone binary run the same code and
 * cannot drift apart.
 */
abstract class SloppyCommandBase extends Command
{
    protected function boolOption(string $name): bool
    {
        return $this->option($name) === true;
    }

    protected function stringOption(string $name, string $default = ''): string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * Null rather than zero when the option was not given, because zero is a
     * meaningful confidence floor and must stay distinguishable from silence.
     */
    protected function intOption(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('--%s must be a number.', $name));
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    protected function stringListOption(string $name): array
    {
        $value = $this->option($name);

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        /** @var mixed $item */
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }
}
