<?php

declare(strict_types=1);

namespace Filament\Widgets;

/**
 * A stand-in for Filament's widget base class.
 *
 * Deliberately empty. The real one extends a Livewire component and declares
 * `$view` -- statically in Filament v3, as an instance property in v4 -- which
 * is exactly why SloppyHealthWidget overrides `render()` instead of setting
 * that property: a subclass that declared it would work on one major version
 * and fatal on the other.
 */
abstract class Widget
{
    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [];
    }
}
