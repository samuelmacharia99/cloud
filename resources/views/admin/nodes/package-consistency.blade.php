@extends('layouts.admin')

@section('title', 'DirectAdmin Package Consistency')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.nodes.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Nodes</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Package Consistency</p>
</div>
@endsection

@section('content')
@php
    $totalCells = 0;
    $okCells = 0;
    $diffCells = 0;
    $missingCells = 0;
    $unknownCells = 0;
    foreach ($rows as $row) {
        foreach ($row['cells'] as $cell) {
            $totalCells++;
            match ($cell['status']) {
                'ok' => $okCells++,
                'diff' => $diffCells++,
                'missing' => $missingCells++,
                'unknown' => $unknownCells++,
                default => null,
            };
        }
    }
    $hasIssues = $diffCells > 0 || $missingCells > 0;
@endphp

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">DirectAdmin Package Consistency</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">
                Live comparison of hosting packages across every active DirectAdmin server.
                Generated {{ $generated_at->diffForHumans() }} &middot;
                cached for 5 minutes.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.shared-hosting.package-consistency', ['refresh' => 1]) }}"
               class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Refresh
            </a>
            <a href="{{ route('admin.nodes.index') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-lg font-medium text-sm transition">
                Back to Nodes
            </a>
        </div>
    </div>

    <!-- Summary -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">DA Nodes</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ $nodes->count() }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">Unique Packages</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ count($rows) }}</p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">Mismatches</p>
            <p class="text-2xl font-bold {{ $diffCells > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                {{ $diffCells }}
            </p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">Missing</p>
            <p class="text-2xl font-bold {{ $missingCells > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                {{ $missingCells }}
            </p>
        </div>
    </div>

    <!-- Errors Banner -->
    @if(!empty($errors))
        <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 rounded-xl p-5">
            <p class="font-semibold text-amber-800 dark:text-amber-200 mb-2">⚠ Some nodes could not be reached</p>
            <ul class="text-sm text-amber-700 dark:text-amber-300 space-y-1">
                @foreach($errors as $nodeId => $message)
                    @php($n = $nodes->firstWhere('id', $nodeId))
                    <li>
                        <span class="font-medium">{{ $n?->name ?? "Node #$nodeId" }}:</span>
                        {{ $message }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Empty State -->
    @if($nodes->isEmpty())
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-12 text-center">
            <p class="text-slate-700 dark:text-slate-300 font-medium">No active DirectAdmin nodes configured.</p>
            <a href="{{ route('admin.nodes.create', ['type' => 'directadmin']) }}" class="mt-4 inline-block text-blue-600 dark:text-blue-400 hover:underline">
                Add a DirectAdmin node →
            </a>
        </div>
    @elseif(empty($rows))
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-12 text-center">
            <p class="text-slate-700 dark:text-slate-300 font-medium">No packages found on any active DirectAdmin node.</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">Make sure the API credentials are correct and the servers have packages defined.</p>
        </div>
    @else
        <!-- Matrix -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center gap-4 flex-wrap text-xs">
                <span class="font-semibold text-slate-700 dark:text-slate-300">Legend:</span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-sm bg-emerald-500"></span>
                    <span class="text-slate-600 dark:text-slate-400">Identical to reference</span>
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-sm bg-amber-500"></span>
                    <span class="text-slate-600 dark:text-slate-400">Same key, different shape</span>
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-sm bg-red-500"></span>
                    <span class="text-slate-600 dark:text-slate-400">Missing on this node</span>
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-sm bg-slate-400"></span>
                    <span class="text-slate-600 dark:text-slate-400">Could not contact node</span>
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800">
                            <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white sticky left-0 bg-slate-50 dark:bg-slate-800/50 z-10">
                                Package
                            </th>
                            @foreach($nodes as $node)
                                <th class="text-center py-3 px-4 font-semibold text-slate-900 dark:text-white min-w-[160px]">
                                    <a href="{{ route('admin.nodes.show', $node) }}" class="hover:text-blue-600 dark:hover:text-blue-400">
                                        {{ $node->name }}
                                    </a>
                                    <p class="text-xs font-normal text-slate-500 dark:text-slate-400 mt-0.5 font-mono">{{ $node->hostname }}</p>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach($rows as $row)
                            @php($rowHasIssue = collect($row['cells'])->contains(fn ($c) => in_array($c['status'], ['diff', 'missing'])))
                            <tr class="{{ $rowHasIssue ? 'bg-amber-50/30 dark:bg-amber-950/10' : '' }}">
                                <td class="py-3 px-4 sticky left-0 bg-white dark:bg-slate-900 z-10 border-r border-slate-200 dark:border-slate-800">
                                    <p class="font-semibold text-slate-900 dark:text-white">{{ $row['name'] ?? $row['key'] }}</p>
                                    <code class="text-xs text-slate-500 dark:text-slate-400 font-mono">{{ $row['key'] }}</code>
                                </td>
                                @foreach($nodes as $node)
                                    @php($cell = $row['cells'][$node->id] ?? ['status' => 'unknown'])
                                    <td class="py-3 px-4 text-center align-top">
                                        @switch($cell['status'])
                                            @case('ok')
                                                <div class="inline-flex flex-col items-center gap-1">
                                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                                    </span>
                                                    <span class="text-xs text-slate-500 dark:text-slate-400">
                                                        {{ rtrim(rtrim(number_format((float) ($cell['pkg']['disk_quota'] ?? 0), 1), '0'), '.') }}GB
                                                    </span>
                                                </div>
                                                @break
                                            @case('diff')
                                                <details class="text-left">
                                                    <summary class="inline-flex flex-col items-center gap-1 cursor-pointer">
                                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300">
                                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                                        </span>
                                                        <span class="text-xs text-amber-700 dark:text-amber-400 font-medium">{{ count($cell['diffs']) }} diff{{ count($cell['diffs']) > 1 ? 's' : '' }}</span>
                                                    </summary>
                                                    <div class="mt-2 text-xs space-y-1 bg-amber-50 dark:bg-amber-950/30 p-2 rounded border border-amber-200 dark:border-amber-900 inline-block text-left">
                                                        @foreach($cell['diffs'] as $field => $diff)
                                                            <div>
                                                                <span class="font-semibold text-amber-800 dark:text-amber-200">{{ str_replace('_', ' ', $field) }}:</span>
                                                                <span class="text-slate-700 dark:text-slate-300">{{ $diff['this'] ?? '∅' }}</span>
                                                                <span class="text-slate-500 dark:text-slate-500">(ref: {{ $diff['ref'] ?? '∅' }})</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </details>
                                                @break
                                            @case('missing')
                                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300" title="Package not found on this node">
                                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                                </span>
                                                <p class="text-xs text-red-600 dark:text-red-400 mt-1">missing</p>
                                                @break
                                            @case('unknown')
                                            @default
                                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-400" title="Could not contact this node">
                                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                                                </span>
                                                <p class="text-xs text-slate-500 mt-1">unknown</p>
                                        @endswitch
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if($hasIssues)
            <div class="bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900 rounded-xl p-5 text-sm text-blue-900 dark:text-blue-100">
                <p class="font-semibold mb-1">How to fix mismatches</p>
                <ul class="list-disc list-inside space-y-1 text-blue-800 dark:text-blue-200">
                    <li><span class="font-medium">Same key, different shape</span> — open the DirectAdmin panel on the diverging node and edit the package quotas to match the reference (the leftmost node).</li>
                    <li><span class="font-medium">Missing on this node</span> — create the package on that DA server, then click <em>Sync Now</em> on its node detail page.</li>
                </ul>
            </div>
        @endif
    @endif
</div>
@endsection
