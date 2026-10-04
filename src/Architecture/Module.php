<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * The module a class belongs to: its name, and the namespace everything in it
 * starts with.
 */
final readonly class Module
{
    /**
     * @param  string  $root  Ends with a backslash: `App\Modules\Billing\`.
     */
    public function __construct(
        public string $name,
        public string $root,
    ) {}

    /**
     * A class's name inside the module: `Contracts\Invoices` for
     * `App\Modules\Billing\Contracts\Invoices`.
     */
    public function relativeName(string $fqn): string
    {
        return mb_substr($fqn, mb_strlen($this->root));
    }
}
