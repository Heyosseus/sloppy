<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Integrations;

use Heyosseus\Sloppy\Sloppy;

/**
 * Where every "how are we doing?" surface gets its answer.
 *
 * The Filament widget, the NativePHP menu bar, `sloppy health` and the MCP
 * server all want the same snapshot under the same cache policy. They ask this
 * rather than each deciding for itself how often a project may be re-analysed
 * behind a user's back.
 */
final readonly class HealthReporter
{
    /**
     * @param  int|null  $top  How many ranked findings to keep; null for the configured number.
     */
    public function __construct(private Sloppy $sloppy, private ?int $top = null) {}

    public function cache(): HealthCache
    {
        $health = $this->sloppy->configuration->health();

        return new HealthCache($this->sloppy->configuration->absolutePath($health->cache), $health->ttl);
    }

    /**
     * The current snapshot: cached when it is fresh enough, otherwise
     * analysed now and cached for whoever asks next.
     */
    public function current(bool $fresh = false, ?int $now = null): HealthSnapshot
    {
        $cache = $this->cache();

        if ($fresh) {
            return $this->analyzed($cache);
        }

        return $cache->remember(fn (): HealthSnapshot => $this->snapshot(), $now);
    }

    /**
     * Analyse now, ignoring and refreshing whatever was cached.
     */
    public function snapshot(): HealthSnapshot
    {
        return HealthSnapshot::from(
            $this->sloppy->analyze(),
            $this->sloppy->risks(),
            $this->top ?? $this->sloppy->configuration->health()->top,
        );
    }

    private function analyzed(HealthCache $cache): HealthSnapshot
    {
        $snapshot = $this->snapshot();

        $cache->write($snapshot);

        return $snapshot;
    }
}
