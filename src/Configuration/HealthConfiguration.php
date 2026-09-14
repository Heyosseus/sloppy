<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Configuration;

/**
 * Tunables for the health snapshot, read from `sloppy.health`.
 *
 * The snapshot exists for surfaces that ask "how are we doing?" repeatedly and
 * cheaply -- a Filament widget on every dashboard render, a NativePHP menu bar
 * on every tick. Analysing a project takes seconds, so those surfaces read a
 * cached answer, and what that cache is lives here rather than in each of them.
 */
final readonly class HealthConfiguration
{
    public function __construct(
        public string $cache = '.sloppy-health.json',
        public int $ttl = 900,
        public int $top = 5,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $values = new ConfigReader($config);

        return new self(
            cache: $values->string('cache', '.sloppy-health.json'),
            ttl: max(0, $values->int('ttl', 900)),
            top: max(1, $values->int('top', 5)),
        );
    }
}
