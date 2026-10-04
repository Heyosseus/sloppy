<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

use PhpParser\Node;

/**
 * One place a class uses a capability, and what it wrote to do it.
 */
final readonly class CapabilityUse
{
    /**
     * @param  string  $evidence  The call or injection, as a reader would recognise it: `Order::where()`, `env()`.
     */
    public function __construct(
        public Capability $capability,
        public Node $node,
        public string $evidence,
    ) {}
}
