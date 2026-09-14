<?php

declare(strict_types=1);

namespace Filament;

/**
 * A stand-in for Filament's panel, with the one method the plugin calls.
 *
 * @see \Filament\Contracts\Plugin
 */
class Panel
{
    /** @var list<class-string> */
    public array $registeredWidgets = [];

    /**
     * @param  list<class-string>  $widgets
     */
    public function widgets(array $widgets): static
    {
        $this->registeredWidgets = [...$this->registeredWidgets, ...$widgets];

        return $this;
    }
}
