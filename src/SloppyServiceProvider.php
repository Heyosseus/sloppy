<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy;

use Illuminate\Support\ServiceProvider;
use Override;

final class SloppyServiceProvider extends ServiceProvider
{
    /**
     * Register Sloppy's services into the container.
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sloppy.php', 'sloppy');
    }

    /**
     * Bootstrap the package's publishable assets.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sloppy.php' => $this->app->configPath('sloppy.php'),
            ], 'sloppy-config');
        }
    }
}
