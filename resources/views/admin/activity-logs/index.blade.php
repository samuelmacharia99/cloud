@extends('layouts.admin')

@section('title', 'Audit Log')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Audit Log</p>
@endsection

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Admin Audit Log</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Sensitive admin actions: transfers, wallet adjustments, impersonation, and payment approvals.</p>
    </div>

    <form method="GET" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Description or action" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Action</label>
            <select name="action" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm">
                <option value="">All actions</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg">Filter</button>
            <a href="{{ route('admin.activity-logs.index') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-700 dark:text-slate-300 font-medium">Clear</a>
        </div>
    </form>

    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="px-6 py-3 text-left font-semibold">When</th>
                        <th class="px-6 py-3 text-left font-semibold">Admin</th>
                        <th class="px-6 py-3 text-left font-semibold">Action</th>
                        <th class="px-6 py-3 text-left font-semibold">Description</th>
                        <th class="px-6 py-3 text-left font-semibold">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    @forelse($logs as $log)
                        <tr>
                            <td class="px-6 py-3 text-slate-600 whitespace-nowrap">{{ $log->created_at->format('M d, Y H:i') }}</td>
                            <td class="px-6 py-3">{{ $log->admin?->name ?? 'System' }}</td>
                            <td class="px-6 py-3"><code class="text-xs bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded">{{ $log->action }}</code></td>
                            <td class="px-6 py-3 text-slate-700 dark:text-slate-300">{{ $log->description }}</td>
                            <td class="px-6 py-3 text-slate-500 font-mono text-xs">{{ $log->ip_address }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">No audit entries yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($logs->hasPages())
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800">{{ $logs->links() }}</div>
        @endif
    </div>
</div>
@endsection
