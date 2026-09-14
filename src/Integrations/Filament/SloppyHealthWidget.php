<?php

declare(strict_types=1);

namespace Heyosseus\Sloppy\Integrations\Filament;

use Filament\Widgets\Widget;
use Heyosseus\Sloppy\Integrations\HealthReporter;
use Heyosseus\Sloppy\Integrations\HealthSnapshot;
use Heyosseus\Sloppy\Sloppy;
use Illuminate\Contracts\View\View;

/**
 * The score, the severity breakdown and what to read first, on a dashboard.
 *
 * It renders from the cached snapshot, never from an analysis: a widget that
 * parsed the codebase on every page load would make the dashboard as slow as
 * the analyser, and the first thing the team would do is remove it. Keep the
 * cache warm with a scheduled `sloppy:health --fresh`.
 *
 * `render()` is overridden rather than the `$view` property set, because that
 * property is static in Filament v3 and an instance property in v4 -- a child
 * declaring either one breaks under the other version.
 */
final class SloppyHealthWidget extends Widget
{
    public function render(): View
    {
        return view('sloppy::widgets.health', $this->getViewData());
    }

    public function snapshot(): HealthSnapshot
    {
        /** @var Sloppy $sloppy */
        $sloppy = app(Sloppy::class);

        return (new HealthReporter($sloppy))->current();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $snapshot = $this->snapshot();

        return [
            'snapshot' => $snapshot,
            'score' => $snapshot->score,
            'label' => $snapshot->label(),
            'band' => $snapshot->band->value,
            'findings' => $snapshot->findings,
            'files' => $snapshot->files,
            'severities' => array_filter($snapshot->bySeverity, static fn (int $count): bool => $count > 0),
            'top' => $snapshot->top,
            'generatedAt' => $snapshot->generatedAt,
        ];
    }
}
