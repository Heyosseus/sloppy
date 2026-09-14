<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Integrations\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Sloppy as a Filament panel plugin.
 *
 * Registered in a panel it adds the health widget, so the team that already
 * looks at an admin panel every morning sees the codebase's score next to
 * everything else they measure. Code quality that lives only in a CI log is
 * something a team reads when it fails; on a dashboard it is something they
 * watch move.
 *
 * ```php
 * $panel->plugin(SloppyPlugin::make());
 * ```
 *
 * Filament is not a dependency of this package. The class is only loaded when
 * a panel asks for it, which cannot happen in a project that does not have
 * Filament installed.
 */
final class SloppyPlugin implements Plugin
{
    /**
     * @var list<class-string>
     */
    private array $widgets = [SloppyHealthWidget::class];

    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'sloppy';
    }

    public function register(Panel $panel): void
    {
        $panel->widgets($this->widgets);
    }

    /**
     * Nothing to do once the panel is up: the widget reads a cached snapshot
     * when it renders, so there is no state to prepare here.
     */
    public function boot(Panel $panel): void {}

    /**
     * Replace the registered widgets, for a panel that wants its own.
     *
     * @param  list<class-string>  $widgets
     */
    public function widgets(array $widgets): self
    {
        $this->widgets = $widgets;

        return $this;
    }
}
