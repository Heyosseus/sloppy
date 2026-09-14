<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Integrations\NativePhp;

use Heyosseus\Sloppy\Analysis\Severity;
use Heyosseus\Sloppy\Configuration\Configuration;
use Heyosseus\Sloppy\Configuration\FrameworkDetector;
use Heyosseus\Sloppy\Integrations\HealthReporter;
use Heyosseus\Sloppy\Integrations\HealthSnapshot;
use Heyosseus\Sloppy\Sloppy;

/**
 * Sloppy in a NativePHP desktop app.
 *
 * A NativePHP app is a Laravel app, so everything else in this package already
 * works there. What it does not have is a place to put a number: no CI log, no
 * merge request, no terminal anyone is watching. It has a menu bar.
 *
 * This turns a snapshot into the few strings that fit in one -- a label short
 * enough for the bar itself, a handful of items for the menu behind it, and a
 * notification for when the score drops -- so the integration is a few lines
 * in the app's service provider and no NativePHP classes are referenced here.
 *
 * ```php
 * MenuBar::create()->label(app(DesktopHealth::class)->menuLabel());
 * ```
 */
final readonly class DesktopHealth
{
    public function __construct(private Sloppy $sloppy) {}

    /**
     * Whether the analysed project is itself a NativePHP application.
     */
    public static function isDesktopApp(Configuration $configuration): bool
    {
        return (new FrameworkDetector($configuration->basePath))->has('nativephp');
    }

    public function snapshot(bool $fresh = false): HealthSnapshot
    {
        return (new HealthReporter($this->sloppy))->current(fresh: $fresh);
    }

    /**
     * Short enough for a menu bar: a score, a state, and nothing else.
     */
    public function menuLabel(?HealthSnapshot $snapshot = null): string
    {
        $snapshot ??= $this->snapshot();

        return sprintf('%s %d/100', $this->indicator($snapshot), $snapshot->score);
    }

    /**
     * The menu behind the label: the summary, then what to read first.
     *
     * @return list<array{label: string, detail: string}>
     */
    public function menuItems(?HealthSnapshot $snapshot = null): array
    {
        $snapshot ??= $this->snapshot();
        $items = [[
            'label' => $snapshot->summary(),
            'detail' => sprintf('%s lines analysed', number_format($snapshot->lines)),
        ]];

        foreach ($snapshot->top as $finding) {
            $items[] = [
                'label' => sprintf('%s %s', $finding['rule'], $finding['name']),
                'detail' => sprintf('%s:%d', $finding['file'], $finding['line']),
            ];
        }

        return $items;
    }

    /**
     * A desktop notification, for the app that wants to say something when the
     * score moves -- and to say nothing at all when it has not.
     *
     * @return array{title: string, body: string}|null
     */
    public function notification(?HealthSnapshot $previous = null, ?HealthSnapshot $snapshot = null): ?array
    {
        $snapshot ??= $this->snapshot();

        if ($previous instanceof HealthSnapshot && $previous->score <= $snapshot->score) {
            return null;
        }

        return [
            'title' => sprintf('Sloppy: %d/100 %s', $snapshot->score, $snapshot->label()),
            'body' => $snapshot->top === []
                ? sprintf('%d finding(s) across %d file(s).', $snapshot->findings, $snapshot->files)
                : sprintf(
                    '%d finding(s). Start with %s in %s.',
                    $snapshot->findings,
                    $snapshot->top[0]['rule'],
                    $snapshot->top[0]['file'],
                ),
        ];
    }

    /**
     * A traffic light in text, because a menu bar has room for one character
     * and no room for a legend explaining it.
     */
    private function indicator(HealthSnapshot $snapshot): string
    {
        if ($snapshot->countAtOrAbove(Severity::High) > 0) {
            return '!';
        }

        return $snapshot->isClean() ? 'OK' : '~';
    }
}
