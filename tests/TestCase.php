<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests;

use Heyosseus\Sloppy\SloppyServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;

abstract class TestCase extends Orchestra
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Applications that take performance seriously turn this on, and a
        // package that lazy loads inside their views does not merely run slowly
        // there -- it throws. Catching that here is cheaper than hearing it from
        // a consumer.
        Model::preventLazyLoading();
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [SloppyServiceProvider::class];
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app->make(Repository::class);

        $config->set('database.default', 'testing');

        // SQLite leaves foreign keys off unless asked. Without this, cascade
        // deletes in the package's schema would silently do nothing under test
        // while working in production -- the tests would be weaker than the
        // database.
        $config->set('database.connections.testing.foreign_key_constraints', true);
    }
}
