<?php

declare(strict_types=1);

namespace Filament\Contracts;

use Filament\Panel;

/**
 * A stand-in for Filament's plugin contract, autoloaded only in this
 * package's own test suite and its static analysis.
 *
 * Filament is not a dependency here -- the integration is optional, and
 * installing a panel framework to test two classes would be a strange trade.
 * The signatures are Filament's own, so a mismatch between this and the real
 * contract fails here rather than in someone's application.
 */
interface Plugin
{
    public function getId(): string;

    public function register(Panel $panel): void;

    public function boot(Panel $panel): void;
}
