@extends('layouts.customer')

@section('title', 'Migrate to App Hosting')

@section('content')
<div class="space-y-6 max-w-3xl">
    <div>
        <a href="{{ route('customer.services.show', $service) }}" class="text-sm text-brand-600 hover:underline">&larr; Back to service</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">Move site to App Hosting</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">
            Copy your WordPress files and database from DirectAdmin into a WordPress container.
            Email stays on DirectAdmin.
        </p>
    </div>

    @if (!empty($inventoryError))
        <div class="ui-card p-5 border-red-200 dark:border-red-900 bg-red-50/80 dark:bg-red-950/30">
            <p class="text-sm text-red-800 dark:text-red-200">Could not inventory this account: {{ $inventoryError }}</p>
        </div>
    @elseif ($inventory)
        <div class="ui-card p-5 space-y-3">
            <h2 class="font-semibold text-slate-900 dark:text-white">Dry-run inventory</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-slate-500">Username</dt>
                    <dd class="font-mono text-slate-900 dark:text-white">{{ $inventory['username'] }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Domain</dt>
                    <dd class="font-mono text-slate-900 dark:text-white">{{ $inventory['domain'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Detected stack</dt>
                    <dd class="font-semibold capitalize text-slate-900 dark:text-white">{{ str_replace('_', ' ', $inventory['stack']) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Docroot</dt>
                    <dd class="font-mono text-xs text-slate-900 dark:text-white break-all">{{ $inventory['docroot'] }}</dd>
                </div>
            </dl>
            @if (!empty($inventory['databases']))
                <div>
                    <p class="text-xs uppercase text-slate-500 mb-1">Databases</p>
                    <ul class="text-sm font-mono text-slate-800 dark:text-slate-200 space-y-1">
                        @foreach ($inventory['databases'] as $db)
                            <li>{{ $db['name'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <ul class="text-sm text-amber-900 dark:text-amber-200 space-y-1 list-disc pl-5">
                @foreach ($inventory['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($targets->isEmpty())
        <div class="ui-card p-6 space-y-3">
            <p class="text-slate-700 dark:text-slate-300">You need a deployed WordPress app hosting service as the migration target.</p>
            <a href="{{ route('customer.select-techstack') }}" class="btn-primary inline-flex">Deploy WordPress app hosting</a>
        </div>
    @else
        <form method="POST" action="{{ route('customer.services.migrate-to-app.store', $service) }}" class="ui-card p-6 space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-semibold text-slate-900 dark:text-white mb-2">Target WordPress container</label>
                <select name="target_service_id" required class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2.5">
                    @foreach ($targets as $target)
                        <option value="{{ $target->id }}">#{{ $target->id }} — {{ $target->name }} ({{ $target->product->name }})</option>
                    @endforeach
                </select>
            </div>
            @if (!empty($inventory['databases']))
                <div>
                    <label class="block text-sm font-semibold text-slate-900 dark:text-white mb-2">Source database (optional)</label>
                    <select name="database_name" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2.5">
                        <option value="">Auto-detect from wp-config</option>
                        @foreach ($inventory['databases'] as $db)
                            <option value="{{ $db['name'] }}">{{ $db['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                <input type="checkbox" name="confirm_email" value="1" required class="mt-1 rounded border-slate-300">
                <span>I understand email mailboxes stay on DirectAdmin and I will update DNS after cutover.</span>
            </label>
            <button type="submit" class="btn-primary" @disabled(($inventory['stack'] ?? '') !== 'wordpress' && empty($inventory['has_wp_config']))>
                Queue WordPress migration
            </button>
        </form>
    @endif
</div>
@endsection
