<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;

it('merges its configuration into the application', function (): void {
    $config = app(Repository::class);

    expect($config->get('sloppy.enabled'))->toBeTrue();
});
