{{--
    Self-contained CSS rather than Tailwind utility classes or Filament's own
    Blade components.

    Utilities were the first attempt and were wrong: Filament v4 compiles only
    its own `fi-*` classes, so a vendor view written in `rounded-xl bg-white
    p-6` resolves to no rules at all inside a real panel. Filament's Blade
    components would style correctly but make Filament a hard dependency and
    differ between v3 and v4. Plain scoped CSS renders the same in a panel, in
    a project with no build step, and in a test without Filament installed.

    The palette is the widget's own for the same reason: `--gray-500` and
    friends are injected by the panel at runtime and changed format between v3
    and v4, so relying on them would tie the widget to a version. A health
    indicator is semantic red/amber/green anyway, not a branded surface.
--}}
<div class="fi-wi-sloppy" data-band="{{ $band }}">
    <style>
        .fi-wi-sloppy {
            --sl-surface: #ffffff;
            --sl-ring: rgb(9 9 11 / 0.05);
            --sl-strong: #09090b;
            --sl-body: #374151;
            --sl-muted: #6b7280;
            --sl-faint: #9ca3af;
            --sl-good-bg: #ecfdf5; --sl-good-fg: #047857;
            --sl-warn-bg: #fffbeb; --sl-warn-fg: #b45309;
            --sl-bad-bg: #fef2f2; --sl-bad-fg: #b91c1c;

            box-sizing: border-box;
            padding: 1.5rem;
            border-radius: 0.75rem;
            background: var(--sl-surface);
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05), 0 0 0 1px var(--sl-ring);
            font-size: 0.875rem;
            line-height: 1.4;
            color: var(--sl-body);
        }

        :is(.dark, [data-theme='dark']) .fi-wi-sloppy {
            --sl-surface: #111827;
            --sl-ring: rgb(255 255 255 / 0.1);
            --sl-strong: #ffffff;
            --sl-body: #e5e7eb;
            --sl-muted: #9ca3af;
            --sl-faint: #6b7280;
            --sl-good-bg: rgb(16 185 129 / 0.12); --sl-good-fg: #6ee7b7;
            --sl-warn-bg: rgb(245 158 11 / 0.12); --sl-warn-fg: #fcd34d;
            --sl-bad-bg: rgb(239 68 68 / 0.12); --sl-bad-fg: #fca5a5;
        }

        .fi-wi-sloppy * { box-sizing: border-box; }

        .fi-wi-sloppy__head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .fi-wi-sloppy__caption {
            margin: 0;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--sl-muted);
        }

        .fi-wi-sloppy__score {
            margin: 0.125rem 0 0;
            font-size: 1.875rem;
            line-height: 1.1;
            font-weight: 700;
            letter-spacing: -0.025em;
            color: var(--sl-strong);
        }

        .fi-wi-sloppy__score span {
            font-size: 1rem;
            font-weight: 400;
            color: var(--sl-muted);
        }

        .fi-wi-sloppy__band {
            flex: none;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .fi-wi-sloppy[data-band='clean'] .fi-wi-sloppy__band,
        .fi-wi-sloppy[data-band='healthy'] .fi-wi-sloppy__band {
            background: var(--sl-good-bg);
            color: var(--sl-good-fg);
        }

        .fi-wi-sloppy[data-band='needs_attention'] .fi-wi-sloppy__band {
            background: var(--sl-warn-bg);
            color: var(--sl-warn-fg);
        }

        .fi-wi-sloppy[data-band='sloppy'] .fi-wi-sloppy__band,
        .fi-wi-sloppy[data-band='severe'] .fi-wi-sloppy__band {
            background: var(--sl-bad-bg);
            color: var(--sl-bad-fg);
        }

        .fi-wi-sloppy__summary {
            margin: 0.5rem 0 0;
            color: var(--sl-muted);
        }

        .fi-wi-sloppy__severities {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem 1rem;
            margin: 0.75rem 0 0;
            padding: 0;
            list-style: none;
        }

        .fi-wi-sloppy__severities strong {
            font-weight: 600;
            color: var(--sl-strong);
        }

        .fi-wi-sloppy__label {
            margin: 1rem 0 0;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--sl-muted);
        }

        .fi-wi-sloppy__top {
            margin: 0.5rem 0 0;
            padding: 0;
            list-style: none;
        }

        .fi-wi-sloppy__top li + li { margin-top: 0.25rem; }

        .fi-wi-sloppy__rule {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.75rem;
            color: var(--sl-strong);
        }

        .fi-wi-sloppy__where { color: var(--sl-muted); }

        .fi-wi-sloppy__taken {
            margin: 1rem 0 0;
            font-size: 0.75rem;
            color: var(--sl-faint);
        }
    </style>

    <div class="fi-wi-sloppy__head">
        <div>
            <p class="fi-wi-sloppy__caption">Sloppy score</p>
            <p class="fi-wi-sloppy__score">{{ $score }}<span>/100</span></p>
        </div>

        <span class="fi-wi-sloppy__band">{{ $label }}</span>
    </div>

    <p class="fi-wi-sloppy__summary">{{ $findings }} finding(s) across {{ $files }} file(s).</p>

    @if ($severities !== [])
        <ul class="fi-wi-sloppy__severities">
            @foreach ($severities as $severity => $count)
                <li><strong>{{ $count }}</strong> {{ $severity }}</li>
            @endforeach
        </ul>
    @endif

    @if ($top !== [])
        <p class="fi-wi-sloppy__label">Read first</p>

        <ul class="fi-wi-sloppy__top">
            @foreach ($top as $finding)
                <li>
                    <span class="fi-wi-sloppy__rule">{{ $finding['rule'] }}</span>
                    {{ $finding['name'] }}
                    <span class="fi-wi-sloppy__where">{{ $finding['file'] }}:{{ $finding['line'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="fi-wi-sloppy__taken">
        Snapshot taken {{ $generatedAt > 0 ? date('Y-m-d H:i', $generatedAt) : 'just now' }}.
    </p>
</div>
