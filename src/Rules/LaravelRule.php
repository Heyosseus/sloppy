<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Rules;

/**
 * A rule about Laravel, which therefore only runs in a Laravel project.
 */
abstract class LaravelRule extends BaseRule
{
    final public function requiredFramework(): string
    {
        return 'laravel';
    }
}
