<?php

declare(strict_types=1);

use Heyosseus\Sloppy\SloppyServiceProvider;
use Illuminate\Support\ServiceProvider;

it('publishes its configuration file under a tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(SloppyServiceProvider::class, 'sloppy-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toEndWith('sloppy.php');
});
