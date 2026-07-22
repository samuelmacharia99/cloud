@php
    $cpuLabel = rtrim(rtrim(number_format($containerLimits['cpu'], 1, '.', ''), '0'), '.');
    $diskLabel = rtrim(rtrim(number_format($containerLimits['disk_gb'], 1, '.', ''), '0'), '.');
    $backupAgeDays = $latestBackup?->created_at?->diffInDays(now());
    $backupStale = $latestBackup === null || ($backupAgeDays !== null && $backupAgeDays > 7);
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
    <button
        type="button"
        @click="$dispatch('container-set-tab', 'environment')"
        class="text-left rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 hover:border-blue-300 dark:hover:border-blue-600 transition group"
    >
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Environment</p>
        <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">Secrets &amp; env</p>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Manage runtime variables</p>
        <span class="mt-2 inline-block text-xs font-medium text-blue-600 dark:text-blue-400 group-hover:underline">Manage →</span>
    </button>

    <button
        type="button"
        @click="$dispatch('container-set-tab', 'domains')"
        class="text-left rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 hover:border-blue-300 dark:hover:border-blue-600 transition group"
    >
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Domains & SSL</p>
        <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">
            {{ $domainCount }} {{ Str::plural('domain', $domainCount) }}
        </p>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
            @if ($domainCount === 0)
                Add a custom domain
            @elseif ($domainsMissingSsl > 0)
                <span class="text-amber-600 dark:text-amber-400">{{ $domainsMissingSsl }} need SSL</span>
            @else
                All active domains secured
            @endif
        </p>
        <span class="mt-2 inline-block text-xs font-medium text-blue-600 dark:text-blue-400 group-hover:underline">Manage →</span>
    </button>

    <button
        type="button"
        @click="$dispatch('container-set-tab', 'backups')"
        class="text-left rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 hover:border-blue-300 dark:hover:border-blue-600 transition group"
    >
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Backups</p>
        <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">
            @if ($latestBackup)
                {{ $latestBackup->created_at->diffForHumans() }}
            @else
                None yet
            @endif
        </p>
        <p class="mt-1 text-sm {{ $backupStale ? 'text-amber-600 dark:text-amber-400' : 'text-slate-600 dark:text-slate-400' }}">
            @if ($backupStale)
                {{ $latestBackup ? 'Consider creating a fresh backup' : 'Create your first backup' }}
            @else
                Last backup is recent
            @endif
        </p>
        <span class="mt-2 inline-block text-xs font-medium text-blue-600 dark:text-blue-400 group-hover:underline">Manage →</span>
    </button>

    <button
        type="button"
        @click="$dispatch('container-set-tab', 'terminal')"
        class="text-left rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 hover:border-blue-300 dark:hover:border-blue-600 transition group"
    >
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Terminal</p>
        <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">Shell access</p>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Run commands inside <code class="font-mono text-xs">/app</code></p>
        <span class="mt-2 inline-block text-xs font-medium text-blue-600 dark:text-blue-400 group-hover:underline">Open →</span>
    </button>

    <button
        type="button"
        @click="$dispatch('container-set-tab', 'logs')"
        class="text-left rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 hover:border-blue-300 dark:hover:border-blue-600 transition group"
    >
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Logs</p>
        <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-white">App output</p>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Debug errors and deploy issues</p>
        <span class="mt-2 inline-block text-xs font-medium text-blue-600 dark:text-blue-400 group-hover:underline">View →</span>
    </button>
</div>

<p class="text-xs text-slate-500 dark:text-slate-400 mt-3">
    Plan allocation: {{ $cpuLabel }} CPU · {{ $containerLimits['memory_mb'] }} MB RAM · {{ $diskLabel }} GB disk
</p>
