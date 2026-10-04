<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Architecture;

/**
 * A part a class can play in this project's architecture, and how to tell.
 */
final readonly class Role
{
    /**
     * @param  string  $origin  Where the definition came from: `preset laravel` or `sloppy.php`.
     */
    public function __construct(
        public string $name,
        public Matcher $matcher,
        public string $origin,
        public ?string $description = null,
    ) {}

    public function matches(ClassFacts $facts): bool
    {
        return $this->matcher->matches($facts);
    }
}
