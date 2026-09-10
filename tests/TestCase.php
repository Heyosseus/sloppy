<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Tests;

use Heyosseus\Sloppy\SloppyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [SloppyServiceProvider::class];
    }
}
