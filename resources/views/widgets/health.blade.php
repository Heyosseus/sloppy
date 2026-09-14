{{--
    Deliberately plain HTML with Tailwind utility classes rather than Filament's
    own Blade components: the widget then renders identically inside a panel and
    inside a test that does not have Filament installed, and the package keeps
    its promise that Filament is optional.
--}}
<div class="fi-wi-sloppy rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="flex items-baseline justify-between gap-3">
        <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Sloppy score</p>
            <p class="text-3xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ $score }}<span class="text-base font-normal text-gray-500 dark:text-gray-400">/100</span>
            </p>
        </div>

        <span @class([
            'rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
            'bg-success-50 text-success-700 ring-success-600/20' => in_array($band, ['clean', 'healthy'], true),
            'bg-warning-50 text-warning-700 ring-warning-600/20' => $band === 'needs_attention',
            'bg-danger-50 text-danger-700 ring-danger-600/20' => in_array($band, ['sloppy', 'severe'], true),
        ])>
            {{ $label }}
        </span>
    </div>

    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        {{ $findings }} finding(s) across {{ $files }} file(s).
    </p>

    @if ($severities !== [])
        <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
            @foreach ($severities as $severity => $count)
                <li><span class="font-medium">{{ $count }}</span> {{ $severity }}</li>
            @endforeach
        </ul>
    @endif

    @if ($top !== [])
        <p class="mt-4 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Read first</p>

        <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-200">
            @foreach ($top as $finding)
                <li>
                    <span class="font-mono text-xs">{{ $finding['rule'] }}</span>
                    {{ $finding['name'] }}
                    <span class="text-gray-500 dark:text-gray-400">{{ $finding['file'] }}:{{ $finding['line'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">
        Snapshot taken {{ $generatedAt > 0 ? date('Y-m-d H:i', $generatedAt) : 'just now' }}.
    </p>
</div>
