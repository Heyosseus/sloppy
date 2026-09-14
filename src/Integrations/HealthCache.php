<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Integrations;

/**
 * The last {@see HealthSnapshot}, on disk.
 *
 * A dashboard widget must render in milliseconds and an analysis takes
 * seconds, so the widget reads this and something slower fills it. Every
 * failure here -- missing file, unreadable file, malformed JSON, a directory
 * we may not write to -- answers "no snapshot" rather than throwing: a health
 * indicator that can take a page down is worse than no health indicator.
 */
final readonly class HealthCache
{
    public function __construct(public string $path, public int $ttl = 900) {}

    /**
     * The cached snapshot, or null when there is none worth using.
     */
    public function read(?int $now = null): ?HealthSnapshot
    {
        $contents = is_file($this->path) ? @file_get_contents($this->path) : false;

        if ($contents === false) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $snapshot = HealthSnapshot::fromArray($decoded);

        return $snapshot->isStale($this->ttl, $now) ? null : $snapshot;
    }

    /**
     * Store a snapshot, reporting whether it could be stored.
     */
    public function write(HealthSnapshot $snapshot): bool
    {
        $encoded = json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return false;
        }

        return @file_put_contents($this->path, $encoded."\n") !== false;
    }

    /**
     * The cached snapshot if it is fresh, otherwise a new one, stored.
     *
     * @param  callable(): HealthSnapshot  $fresh
     */
    public function remember(callable $fresh, ?int $now = null): HealthSnapshot
    {
        $cached = $this->read($now);

        if ($cached instanceof HealthSnapshot) {
            return $cached;
        }

        $snapshot = $fresh();

        $this->write($snapshot);

        return $snapshot;
    }

    public function forget(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
