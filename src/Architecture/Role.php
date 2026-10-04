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
     * @param  bool  $intendedAbstraction  Interfaces and wrappers in this role are the design, so SL301 to SL303 leave them alone.
     */
    public function __construct(
        public string $name,
        public Matcher $matcher,
        public string $origin,
        public ?string $description = null,
        public bool $intendedAbstraction = false,
    ) {}

    public function matches(ClassFacts $facts): bool
    {
        return $this->matcher->matches($facts);
    }
}
