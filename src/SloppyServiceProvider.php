<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy;

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Console\Commands\SloppyBaselineCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyDiffCommand;
use Illuminate\Contracts\Config\Repository;
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

        $this->app->singleton(Configuration::class, function (): Configuration {
            /** @var Repository $config */
            $config = $this->app->make(Repository::class);

            /** @var array<string, mixed> $values */
            $values = $config->get('sloppy', []);

            return Configuration::fromArray($values, $this->app->basePath());
        });

        $this->app->singleton(Sloppy::class, fn (): Sloppy => new Sloppy(
            $this->app->make(Configuration::class),
        ));
    }

    /**
     * Bootstrap the package's console surface.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/sloppy.php' => $this->app->configPath('sloppy.php'),
        ], 'sloppy-config');

        $this->commands([
            SloppyCommand::class,
            SloppyDiffCommand::class,
            SloppyBaselineCommand::class,
        ]);
    }
}
