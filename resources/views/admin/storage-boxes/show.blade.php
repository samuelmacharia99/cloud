@extends('layouts.admin')

@section('title', 'Storage Box')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.nodes.index', ['type' => 'storage_box']) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Nodes</a>
    <span class="text-slate-400">/</span>
    <p class="font-medium text-slate-900 dark:text-white">{{ $box['name'] }}</p>
</div>
@endsection

@section('content')
@php
    $disk = $box['disk'] ?? [];
    $diskAvailable = ($disk['available'] ?? false) === true;
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $box['name'] }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1 font-mono text-sm">{{ $box['host'] }}:{{ $box['port'] }} · {{ $box['base_path'] ?: '(home)' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.nodes.index') }}" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-900 dark:text-white font-medium rounded-lg transition text-sm">Back to Nodes</a>
            <form method="POST" action="{{ $box['refresh_url'] }}">
                @csrf
                <button type="submit" class="px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg transition text-sm">Refresh capacity</button>
            </form>
            <a href="{{ $box['settings_url'] }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">Provisioning settings</a>
        </div>
    </div>

    @if (session('success'))
        <div class="ui-card p-4 border border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/30 text-emerald-800 dark:text-emerald-200 text-sm">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="ui-card p-4 border border-red-200 dark:border-red-900 bg-red-50 dark:bg-red-950/30 text-red-800 dark:text-red-200 text-sm">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="ui-card p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400">Platform archives</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">{{ number_format($box['backup_count']) }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ formatBytes((int) $box['backup_bytes']) }} tracked in Talksasa</p>
        </div>
        <div class="ui-card p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400">Retention policy</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">{{ $box['retention_days'] }} days</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $box['auto_purge_enabled'] ? 'Automatic nightly purge enabled' : 'Manual purge only' }}</p>
        </div>
        <div class="ui-card p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400">Eligible for purge</p>
            <p class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">{{ number_format($box['eligible_purge_count']) }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ formatBytes((int) $box['eligible_purge_bytes']) }} older than {{ $box['retention_days'] }} days</p>
        </div>
        <div class="ui-card p-6">
            <p class="text-sm text-slate-600 dark:text-slate-400">Last backup upload</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">
                @if (! empty($box['last_backup_at']))
                    {{ \Illuminate\Support\Carbon::parse($box['last_backup_at'])->diffForHumans(null, true) }}
                @else
                    Never
                @endif
            </p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $box['is_active_driver'] ? 'Active backup target' : 'Standby (driver not selected)' }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div class="ui-card p-6 xl:col-span-2 space-y-6">
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Live capacity</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Read from the Storage Box via SSH (<code class="font-mono text-xs">df -h</code>). Enable SSH support on port 23 in Hetzner Console if this fails.</p>
            </div>

            @if ($diskAvailable)
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Total quota</p>
                        <p class="text-xl font-semibold text-slate-900 dark:text-white mt-1">{{ $disk['total_human'] }}</p>
                        @if (! empty($disk['total_bytes']))
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ formatBytes((int) $disk['total_bytes']) }}</p>
                        @endif
                    </div>
                    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Used</p>
                        <p class="text-xl font-semibold text-slate-900 dark:text-white mt-1">{{ $disk['used_human'] }}</p>
                        @if (! empty($disk['used_bytes']))
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ formatBytes((int) $disk['used_bytes']) }}</p>
                        @endif
                    </div>
                    <div class="rounded-xl border border-emerald-200 dark:border-emerald-900 p-4 bg-emerald-50/50 dark:bg-emerald-950/20">
                        <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Remaining</p>
                        <p class="text-xl font-semibold text-emerald-700 dark:text-emerald-300 mt-1">{{ $disk['available_human'] }}</p>
                        @if (! empty($disk['available_bytes']))
                            <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1">{{ formatBytes((int) $disk['available_bytes']) }}</p>
                        @endif
                    </div>
                </div>

                @if (! empty($disk['capacity_percent']))
                    <div>
                        <div class="flex items-center justify-between text-sm mb-2">
                            <span class="text-slate-600 dark:text-slate-400">Filesystem usage</span>
                            <span class="font-semibold text-slate-900 dark:text-white">{{ $disk['capacity_percent'] }}%</span>
                        </div>
                        <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-3">
                            <div class="bg-cyan-500 h-3 rounded-full" style="width: {{ min((int) $disk['capacity_percent'], 100) }}%"></div>
                        </div>
                    </div>
                @endif

                @if (! empty($disk['backup_path_human']))
                    <p class="text-sm text-slate-600 dark:text-slate-400">Backup base path <code class="font-mono">{{ $box['base_path'] }}</code> currently uses <strong class="text-slate-900 dark:text-white">{{ $disk['backup_path_human'] }}</strong> on disk.</p>
                @endif

                @if (! empty($disk['fetched_at']))
                    <p class="text-xs text-slate-500 dark:text-slate-400">Last checked {{ \Illuminate\Support\Carbon::parse($disk['fetched_at'])->diffForHumans() }}.</p>
                @endif
            @else
                <div class="rounded-xl border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/20 p-4 text-sm text-amber-900 dark:text-amber-100">
                    Could not read live capacity: {{ $disk['error'] ?? 'Unknown error' }}.
                </div>
            @endif
        </div>

        <div class="ui-card p-6 space-y-4">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Specs</h2>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Provider</dt>
                    <dd class="text-slate-900 dark:text-white mt-0.5">{{ $disk['provider'] ?? 'Hetzner Storage Box' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Access</dt>
                    <dd class="text-slate-900 dark:text-white mt-0.5 font-mono">{{ $disk['access'] ?? ('SFTP/SSH port '.$box['port']) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Filesystem</dt>
                    <dd class="text-slate-900 dark:text-white mt-0.5 font-mono">{{ $disk['filesystem'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Mount point</dt>
                    <dd class="text-slate-900 dark:text-white mt-0.5 font-mono">{{ $disk['mount_point'] ?? '/home' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500 dark:text-slate-400">Username</dt>
                    <dd class="text-slate-900 dark:text-white mt-0.5 font-mono">{{ $box['username'] }}</dd>
                </div>
                @if (! empty($disk['server_version']))
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Server</dt>
                        <dd class="text-slate-900 dark:text-white mt-0.5 font-mono text-xs break-all">{{ $disk['server_version'] }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>

    <div class="ui-card p-6 space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Purge old backups</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                Permanently deletes completed container backup archives on this Storage Box and in Talksasa when they are older than the retention window.
                This cannot be undone.
            </p>
        </div>

        <form method="POST" action="{{ $box['purge_url'] }}" class="space-y-4 max-w-xl" data-confirm="Permanently delete {{ number_format($box['eligible_purge_count']) }} backup archive(s) older than the retention period from the Storage Box?" data-confirm-title="Purge Storage Box backups">
            @csrf
            <div>
                <label for="days" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Delete archives older than (days)</label>
                <input type="number" id="days" name="days" min="1" max="3650" value="{{ old('days', $box['retention_days']) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white" required>
            </div>
            <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                <input type="checkbox" name="confirm" value="1" @checked(old('confirm')) class="rounded mt-0.5" required>
                <span>I understand this permanently deletes backup tarballs from the Storage Box and removes their Talksasa records.</span>
            </label>
            @error('confirm')
                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
            <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg text-sm" @disabled($box['eligible_purge_count'] === 0)>
                Purge {{ number_format($box['eligible_purge_count']) }} eligible archive(s)
            </button>
        </form>
    </div>
</div>
@endsection
