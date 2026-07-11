@extends('layouts.admin')

@section('title', 'Migrate to Container')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.services.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Services</a>
    <span class="text-slate-400">/</span>
    <a href="{{ route('admin.services.show', $service) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $service->name }}</a>
    <span class="text-slate-400">/</span>
    <span class="font-medium text-slate-600 dark:text-slate-400">Migrate to App Hosting</span>
</div>
@endsection

@section('content')
<div class="space-y-6 max-w-3xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">DA → WordPress container</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">ETL migrator (files + DB). Email remains on DirectAdmin.</p>
    </div>

    @if ($inventoryError)
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $inventoryError }}</div>
    @elseif ($inventory)
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-3">
            <h2 class="font-semibold">Inventory</h2>
            <p class="text-sm">Stack: <strong class="capitalize">{{ $inventory['stack'] }}</strong> · Domain: <span class="font-mono">{{ $inventory['domain'] ?? '—' }}</span></p>
            <p class="text-xs font-mono text-slate-500">{{ $inventory['docroot'] }}</p>
            <ul class="text-sm list-disc pl-5 text-amber-800 dark:text-amber-200">
                @foreach ($inventory['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.services.migrate-to-container.store', $service) }}" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-2">Target WordPress container service</label>
            <select name="target_service_id" required class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2">
                @forelse ($targets as $target)
                    <option value="{{ $target->id }}">#{{ $target->id }} — {{ $target->name }}</option>
                @empty
                    <option value="" disabled>No WordPress container targets for this customer</option>
                @endforelse
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium mb-2">Database name (optional)</label>
            <input type="text" name="database_name" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 font-mono" placeholder="Auto from wp-config / inventory">
        </div>
        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium" @disabled($targets->isEmpty())>
            Queue migration
        </button>
    </form>
</div>
@endsection
