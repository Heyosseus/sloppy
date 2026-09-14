<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy;

use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Console\Commands\SloppyBaselineCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyCiCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyDiffCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyFixCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyHealthCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyHelpCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyMcpCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyReviewCommand;
use Heyosseus\Sloppy\Console\Commands\SloppyRulesCommand;
use Heyosseus\Sloppy\Integrations\HealthReporter;
use Heyosseus\Sloppy\Integrations\NativePhp\DesktopHealth;
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

        // The dashboard-shaped surfaces -- the Filament widget, a NativePHP
        // menu bar -- resolve these rather than constructing an analysis, so
        // they share one cache and one set of rules with everything else.
        $this->app->bind(HealthReporter::class, fn (): HealthReporter => new HealthReporter(
            $this->app->make(Sloppy::class),
        ));

        $this->app->bind(DesktopHealth::class, fn (): DesktopHealth => new DesktopHealth(
            $this->app->make(Sloppy::class),
        ));
    }

    /**
     * Bootstrap the package's console and view surfaces.
     */
    public function boot(): void
    {
        // Views are loaded outside the console check: the Filament widget
        // renders in a browser request, which is the one place this method
        // used to do nothing at all.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sloppy');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/sloppy.php' => $this->app->configPath('sloppy.php'),
        ], 'sloppy-config');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/sloppy'),
        ], 'sloppy-views');

        $this->commands([
            SloppyCommand::class,
            SloppyDiffCommand::class,
            SloppyReviewCommand::class,
            SloppyBaselineCommand::class,
            SloppyCiCommand::class,
            SloppyFixCommand::class,
            SloppyHealthCommand::class,
            SloppyRulesCommand::class,
            SloppyMcpCommand::class,
            SloppyHelpCommand::class,
        ]);
    }
}
