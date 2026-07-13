@extends('layouts.admin')

@section('title', 'Delete node: ' . $node->name)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.nodes.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Nodes</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('admin.nodes.show', $node) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $node->name }}</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Delete</p>
</div>
@endsection

@section('content')
@php
    $selectedTargetId = old('target_node_id', $scanResults['target_node_id'] ?? null);
    $isContainerHost = $node->type === 'container_host';
    $isDirectAdmin = $node->type === 'directadmin';
@endphp

<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Delete {{ $node->name }}</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">
            {{ $node->hostname }} ({{ $node->ip_address }}) · {{ $node->getTypeLabel() }}
        </p>
    </div>

    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 dark:border-red-900/50 dark:bg-red-950/40 px-4 py-3 text-sm text-red-800 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif
    @if (session('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 dark:border-emerald-900/50 dark:bg-emerald-950/40 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    @if ($remaining === 0)
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
            <p class="text-slate-700 dark:text-slate-300">
                No services@if($isDirectAdmin) or assigned resellers@endif are linked to this node. You can delete it permanently.
            </p>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                This only removes the node from Talksasa Cloud. It does <strong>not</strong> delete DirectAdmin accounts, containers, files, or anything else on the physical server.
            </p>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('admin.nodes.show', $node) }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">
                    Cancel
                </a>
                <form method="POST" action="{{ route('admin.nodes.delete', $node) }}" data-confirm="Remove this node from Talksasa Cloud? Accounts and data on the server itself are not deleted." data-confirm-title="Delete node record">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg text-sm">
                        Delete node
                    </button>
                </form>
            </div>
        </div>
    @else
        <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/50 rounded-xl p-5">
            <p class="text-sm text-amber-900 dark:text-amber-100 font-medium">
                {{ $remaining }} record(s) still point at this node
                ({{ $services->count() }} service(s)@if($isDirectAdmin), {{ $resellers->count() }} reseller(s)@endif).
            </p>
            <p class="text-sm text-amber-800 dark:text-amber-200/90 mt-1">
                If you already moved them to another server, select that destination below.
                @if ($isContainerHost)
                    The system will rescan Docker for matching container names and update service / deployment records (and rebind domains when possible).
                @elseif ($isDirectAdmin)
                    The system will rescan the destination DirectAdmin API for matching usernames (hosting accounts and reseller accounts) and update platform node assignments.
                @else
                    Confirm the destination and the system will update each service’s node assignment.
                @endif
            </p>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-5">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Where were the users moved?</h2>

            @if ($targets->isEmpty())
                <p class="text-sm text-red-700 dark:text-red-300">
                    No other active {{ $node->getTypeLabel() }} nodes are available as a destination. Add or activate one first.
                </p>
            @else
                <form method="POST" action="{{ route('admin.nodes.relocate-services', $node) }}" class="space-y-4">
                    @csrf
                    <div>
                        <label for="target_node_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Destination node</label>
                        <select id="target_node_id" name="target_node_id" required
                            class="w-full max-w-xl px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm text-slate-900 dark:text-white">
                            <option value="">Select server…</option>
                            @foreach ($targets as $target)
                                <option value="{{ $target->id }}" @selected((string) $selectedTargetId === (string) $target->id)>
                                    {{ $target->name }} — {{ $target->hostname }} ({{ $target->ip_address }})
                                </option>
                            @endforeach
                        </select>
                        @error('target_node_id')
                            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button type="submit" name="action" value="scan"
                            class="px-4 py-2 bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 text-white font-medium rounded-lg text-sm">
                            Rescan destination
                        </button>
                    </div>
                </form>
                <form method="POST" action="{{ route('admin.nodes.relocate-services', $node) }}" class="mt-3"
                    data-confirm="Update platform records for accounts found on the destination? Only matched users will be reassigned."
                    data-confirm-title="Update user locations">
                    @csrf
                    <input type="hidden" name="target_node_id" value="{{ $selectedTargetId }}">
                    <input type="hidden" name="action" value="apply">
                    <button type="submit"
                        @disabled(! $selectedTargetId)
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium rounded-lg text-sm">
                        Update records from scan
                    </button>
                </form>
            @endif
        </div>

        @if (! empty($scanResults['rows']))
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">
                        Scan results
                        @if (! empty($scanResults['target_name']))
                            <span class="font-normal text-slate-500 dark:text-slate-400">→ {{ $scanResults['target_name'] }}</span>
                        @endif
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/60 text-left text-slate-600 dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">Type</th>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Customer / email</th>
                                @if ($isContainerHost)
                                    <th class="px-4 py-3 font-medium">Container</th>
                                    <th class="px-4 py-3 font-medium">Port</th>
                                @endif
                                @if ($isDirectAdmin)
                                    <th class="px-4 py-3 font-medium">DA username</th>
                                @endif
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach ($scanResults['rows'] as $row)
                                <tr>
                                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300 capitalize">{{ $row['kind'] ?? 'service' }}</td>
                                    <td class="px-4 py-3 text-slate-900 dark:text-white">
                                        @if (($row['kind'] ?? 'service') === 'reseller' && ! empty($row['reseller_id']))
                                            <a href="{{ route('admin.resellers.show', $row['reseller_id']) }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                                                {{ $row['service_name'] }}
                                            </a>
                                        @elseif (! empty($row['service_id']))
                                            <a href="{{ route('admin.services.show', $row['service_id']) }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                                                {{ $row['service_name'] }}
                                            </a>
                                            <span class="text-slate-400">#{{ $row['service_id'] }}</span>
                                        @else
                                            {{ $row['service_name'] ?? '—' }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $row['customer'] ?? '—' }}</td>
                                    @if ($isContainerHost)
                                        <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-300">{{ $row['container_name'] ?? '—' }}</td>
                                        <td class="px-4 py-3 font-mono text-slate-700 dark:text-slate-300">{{ $row['published_port'] ?? '—' }}</td>
                                    @endif
                                    @if ($isDirectAdmin)
                                        <td class="px-4 py-3 font-mono text-xs text-slate-700 dark:text-slate-300">{{ $row['da_username'] ?? '—' }}</td>
                                    @endif
                                    <td class="px-4 py-3">
                                        @if ($row['found'] ?? false)
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">
                                                {{ ($row['running'] ?? false) ? 'Found (active)' : 'Found' }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200">
                                                Missing
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-600 dark:text-slate-400">{{ $row['message'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($services->isNotEmpty())
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Services still on this node</h2>
                <ul class="divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach ($services as $service)
                        @php
                            $daUser = $service->service_meta['username'] ?? $service->external_reference ?? ($service->credentials['username'] ?? null);
                        @endphp
                        <li class="py-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <a href="{{ route('admin.services.show', $service) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                    {{ $service->name }}
                                </a>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ $service->user?->email ?? 'No customer' }}
                                    @if ($service->containerDeployment?->container_name)
                                        · <span class="font-mono">{{ $service->containerDeployment->container_name }}</span>
                                    @endif
                                    @if ($isDirectAdmin && $daUser)
                                        · DA <span class="font-mono">{{ $daUser }}</span>
                                    @endif
                                </p>
                            </div>
                            <span class="text-xs uppercase tracking-wide text-slate-500">{{ $service->status?->value ?? $service->status }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($isDirectAdmin && $resellers->isNotEmpty())
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Resellers still assigned to this node</h2>
                <ul class="divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach ($resellers as $reseller)
                        <li class="py-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <a href="{{ route('admin.resellers.show', $reseller) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                    {{ $reseller->company ?: $reseller->name ?: $reseller->email }}
                                </a>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    {{ $reseller->email }}
                                    @if ($reseller->directadmin_username)
                                        · DA <span class="font-mono">{{ $reseller->directadmin_username }}</span>
                                    @endif
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap gap-3">
            <a href="{{ route('admin.nodes.show', $node) }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800">
                Back to node
            </a>
            <button type="button" disabled
                class="px-4 py-2 bg-red-600/40 text-white font-medium rounded-lg text-sm cursor-not-allowed"
                title="Clear remaining records via rescan/update first">
                Delete blocked until records are reassigned
            </button>
        </div>
    @endif
</div>
@endsection
