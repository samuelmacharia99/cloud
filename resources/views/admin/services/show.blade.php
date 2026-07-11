@extends('layouts.admin')

@section('title', 'Service #' . $service->id)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.services.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Services</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">#{{ $service->id }}</p>
</div>
@endsection

@section('content')
<div class="space-y-6" x-data="{
    editDetailsModal: false,
    upgradeHostingModal: false,
    suspendModal: false,
    transferModal: false,
    transferPreviewLoading: false,
    transferPreview: null,
    transferTargetId: '{{ old('target_user_id', '') }}',
    transferDomain: @json((bool) old('transfer_domain')),
    testConnectionModal: false,
    testConnectionLoading: false,
    testConnectionResult: null,
    async testDirectAdminConnection() {
        if (!{{ $service->id }}) return;
        this.testConnectionLoading = true;
        try {
            const response = await fetch('{{ route('admin.services.test-directadmin', $service) }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Content-Type': 'application/json'
                }
            });
            const data = await response.json();
            this.testConnectionResult = data;
            this.testConnectionModal = true;
        } catch (error) {
            this.testConnectionResult = {
                success: false,
                message: 'Network error: ' + error.message
            };
            this.testConnectionModal = true;
        } finally {
            this.testConnectionLoading = false;
        }
    },
    async loadTransferPreview() {
        if (!this.transferTargetId) {
            this.transferPreview = null;
            return;
        }
        this.transferPreviewLoading = true;
        try {
            const url = new URL('{{ route('admin.services.transfer-preview', $service) }}');
            url.searchParams.set('target_user_id', this.transferTargetId);
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json();
            this.transferPreview = response.ok ? data : { error: data.error || 'Preview failed.' };
        } catch (error) {
            this.transferPreview = { error: 'Network error: ' + error.message };
        } finally {
            this.transferPreviewLoading = false;
        }
    }
}">
    @if (session('success'))
        <div class="rounded-xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/40 px-4 py-3 text-sm text-emerald-900 dark:text-emerald-100">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 px-4 py-3 text-sm text-red-900 dark:text-red-100">
            {{ session('error') }}
        </div>
    @endif
    @if (session('info'))
        <div class="rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/40 px-4 py-3 text-sm text-blue-900 dark:text-blue-100">
            {{ session('info') }}
        </div>
    @endif

    @php
        $daConvert = $service->service_meta['da_convert'] ?? null;
        $canRevertDaConvert = app(\App\Services\Provisioning\DirectAdminToContainerConvertService::class)->canRevertToDirectAdmin($service);
    @endphp
    @if (!empty($daConvert['status']))
        <div class="rounded-xl border px-4 py-3 text-sm {{ ($daConvert['status'] ?? '') === 'completed' ? 'border-emerald-200 bg-emerald-50 dark:bg-emerald-950/40 dark:border-emerald-800 text-emerald-900 dark:text-emerald-100' : (($daConvert['status'] ?? '') === 'failed' ? 'border-red-200 bg-red-50 dark:bg-red-950/40 dark:border-red-800 text-red-900 dark:text-red-100' : (($daConvert['status'] ?? '') === 'reverted' ? 'border-slate-200 bg-slate-50 dark:bg-slate-900 dark:border-slate-700 text-slate-800 dark:text-slate-200' : 'border-indigo-200 bg-indigo-50 dark:bg-indigo-950/40 dark:border-indigo-800 text-indigo-900 dark:text-indigo-100')) }}">
            <p class="font-semibold">DA → App Hosting convert: <span class="uppercase">{{ $daConvert['status'] }}</span></p>
            @if (!empty($daConvert['error']))
                <p class="mt-1">{{ $daConvert['error'] }}</p>
            @endif
            @if (!empty($daConvert['steps']) && is_array($daConvert['steps']))
                <ul class="mt-2 font-mono text-xs space-y-1 opacity-90">
                    @foreach (array_slice($daConvert['steps'], -5) as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ul>
            @endif
            @if (in_array($daConvert['status'] ?? '', ['queued', 'running'], true))
                <p class="mt-2 text-xs opacity-80">
                    Refresh this page for progress.
                    Prefer <code class="font-mono">QUEUE_CONNECTION=database</code> (or redis) with
                    <code class="font-mono">php artisan queue:work --timeout=2400</code>
                    so large imports are not killed by PHP’s web <code class="font-mono">max_execution_time</code>.
                    @if (!empty($daConvert['heartbeat_at']))
                        Last heartbeat: {{ $daConvert['heartbeat_at'] }}
                    @endif
                </p>
            @endif
            @if ($canRevertDaConvert)
                <form method="POST" action="{{ route('admin.services.revert-from-container', $service) }}" class="mt-3" data-confirm="{{ in_array($daConvert['status'] ?? '', ['queued', 'running'], true) ? 'Convert looks stuck. Force revert to DirectAdmin? Stop any queue worker first if it is still processing this job. Delete leftover containers on the node manually.' : 'Restore this service to DirectAdmin? You must delete any leftover container on the node yourself.' }}" data-confirm-title="Revert to DirectAdmin">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-900 text-white text-xs font-medium rounded-lg transition">
                        {{ in_array($daConvert['status'] ?? '', ['queued', 'running'], true) ? 'Force revert (stuck convert)' : 'Revert to DirectAdmin' }}
                    </button>
                </form>
            @endif
            @if (($daConvert['status'] ?? '') === 'reverted' && !empty($daConvert['manual_container_cleanup']))
                <p class="mt-2 text-xs font-mono opacity-80">Manual cleanup: /opt/talksasa/containers/{{ $daConvert['manual_container_cleanup'] }}</p>
            @endif
        </div>
    @endif

    @if ($hasStaleOverlimitFlags ?? false)
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-5 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-amber-950 dark:text-amber-100">Stale package limit flags</p>
                <p class="text-sm text-amber-900 dark:text-amber-200 mt-1">
                    This service still has old over-limit markers in metadata
                    @if (!empty($service->service_meta['package_overlimit_metrics']))
                        ({{ implode(', ', $service->service_meta['package_overlimit_metrics']) }})
                    @endif
                    even though it is active. Refresh usage from DirectAdmin to clear them.
                </p>
            </div>
            <form method="POST" action="{{ route('admin.services.reconcile-hosting', $service) }}" class="shrink-0">
                @csrf
                <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-lg transition">
                    Refresh from DirectAdmin
                </button>
            </form>
        </div>
    @endif

    @if ($service->isSharedHosting() && ($daLivePackage ?? null) && strcasecmp($daLivePackage, $service->product->name) !== 0)
        <div class="rounded-xl border border-orange-200 dark:border-orange-800 bg-orange-50 dark:bg-orange-950/30 px-5 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-orange-950 dark:text-orange-100">DirectAdmin package mismatch</p>
                <p class="text-sm text-orange-900 dark:text-orange-200 mt-1">
                    DirectAdmin reports <strong>{{ $daLivePackage }}</strong> but the platform product is <strong>{{ $service->product->name }}</strong>.
                    Use <strong>Upgrade Hosting</strong> to change the live package, or sync the platform record if DirectAdmin is already correct.
                </p>
            </div>
            <form method="POST" action="{{ route('admin.services.sync-hosting-plan', $service) }}" class="shrink-0">
                @csrf
                <button type="submit" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white text-sm font-medium rounded-lg transition">
                    Sync platform to DirectAdmin
                </button>
            </form>
        </div>
    @endif

    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Service #{{ $service->id }}</h1>
                <p class="text-slate-600 dark:text-slate-400 mt-2">{{ $service->product->name }} • {{ ucfirst(str_replace('_', ' ', $service->product->type)) }}</p>

                <!-- Status badge -->
                <div class="mt-4">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        @if($service->status->value === 'active')
                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                        @elseif($service->status->value === 'pending')
                            bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                        @elseif($service->status->value === 'provisioning')
                            bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                        @elseif($service->status->value === 'suspended')
                            bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300
                        @elseif($service->status->value === 'terminated')
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @elseif($service->status->value === 'failed')
                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                        @endif
                    ">
                        {{ ucfirst($service->status->value) }}
                    </span>
                </div>

                @if ($service->status->value === 'suspended')
                    <div class="mt-4 p-4 rounded-xl border border-orange-200 dark:border-orange-800 bg-orange-50 dark:bg-orange-950/40 max-w-2xl">
                        <p class="text-sm font-semibold text-orange-950 dark:text-orange-100">Service suspended</p>
                        <p class="text-sm text-orange-900 dark:text-orange-200 mt-1">
                            <span class="font-medium">Reason:</span>
                            {{ $enforcementInsight['suspension_message'] ?? 'Reason not recorded' }}
                        </p>
                        @if ($service->suspend_date)
                            <p class="text-xs text-orange-800 dark:text-orange-300 mt-2">
                                Suspended on {{ $service->suspend_date->format('M d, Y g:i A') }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2 flex-wrap">
                @if ($service->isSharedHosting() && ($service->external_reference || filled($service->service_meta['username'] ?? null)))
                    <button type="button" @click="upgradeHostingModal = true" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-medium rounded-lg transition text-sm">
                        Upgrade Hosting
                    </button>
                    <a href="{{ route('admin.services.migrate-to-container', $service) }}" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg transition text-sm">
                        Convert to App Hosting
                    </a>
                @endif

                @if ($canRevertDaConvert ?? false)
                    <form method="POST" action="{{ route('admin.services.revert-from-container', $service) }}" class="inline" data-confirm="Restore this service to DirectAdmin? Delete any leftover container on the node yourself afterward." data-confirm-title="Revert to DirectAdmin">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white font-medium rounded-lg transition text-sm">
                            Revert to DirectAdmin
                        </button>
                    </form>
                @endif

                @if ($service->isContainerHosting() && $service->containerDeployment)
                    <a href="{{ route('admin.services.container.migrate', $service) }}" class="px-4 py-2 bg-slate-700 hover:bg-slate-800 text-white font-medium rounded-lg transition text-sm">
                        Migrate Node
                    </a>
                @endif

                @if (in_array($service->status->value, ['pending', 'provisioning']))
                    <form method="POST" action="{{ route('admin.services.provision', $service) }}" class="inline">
                        @csrf
                        <button type="submit" class="px-4 py-2 {{ $service->status->value === 'provisioning' ? 'bg-orange-600 hover:bg-orange-700' : 'bg-blue-600 hover:bg-blue-700' }} text-white font-medium rounded-lg transition text-sm">
                            {{ $service->status->value === 'provisioning' ? 'Retry Provisioning' : 'Provision' }}
                        </button>
                    </form>
                @endif

                @if ($service->status->value === 'active')
                    <button type="button" @click="suspendModal = true" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition text-sm">
                        Suspend
                    </button>
                @endif

                @if ($service->status->value === 'suspended')
                    <form method="POST" action="{{ route('admin.services.unsuspend', $service) }}" class="inline">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition text-sm">
                            Unsuspend
                        </button>
                    </form>
                @endif

                @if (in_array($service->status->value, ['active', 'suspended', 'pending']))
                    <form method="POST" action="{{ route('admin.services.terminate', $service) }}" class="inline" data-confirm='Are you sure you want to terminate this service?'>
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition text-sm">
                            Terminate
                        </button>
                    </form>
                @endif

                @if ($service->supportsLiveStatusProbe())
                    <form method="POST" action="{{ route('admin.services.refresh-status', $service) }}" class="inline">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg transition text-sm">
                            Refresh Live Status
                        </button>
                    </form>
                @endif

                <form
                    method="POST"
                    action="{{ route('admin.services.destroy', $service) }}"
                    class="inline"
                    data-confirm="{{ ($infrastructureAbsent ?? false)
                        ? 'Delete this service record? DirectAdmin reports no hosting account — only the platform record will be removed.'
                        : 'Delete service #' . $service->id . '? This removes the record and attempts to deprovision infrastructure.' }}"
                >
                    @csrf
                    @method('DELETE')
                    @if ($infrastructureAbsent ?? false)
                        <input type="hidden" name="force" value="1">
                    @endif
                    <button type="submit" class="px-4 py-2 bg-slate-700 hover:bg-slate-800 text-white font-medium rounded-lg transition text-sm">
                        {{ ($infrastructureAbsent ?? false) ? 'Delete record' : 'Delete' }}
                    </button>
                </form>

                @if (! ($infrastructureAbsent ?? false) && $service->supportsLiveStatusProbe())
                    <form
                        method="POST"
                        action="{{ route('admin.services.destroy', $service) }}"
                        class="inline"
                        data-confirm="Force delete without contacting DirectAdmin/container host? Use only if the account is already gone or the API is broken."
                    >
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="force" value="1">
                        <button type="submit" class="px-4 py-2 border border-red-300 dark:border-red-800 text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-950/40 font-medium rounded-lg transition text-sm">
                            Force delete
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    @if ($infrastructureAbsent ?? false)
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
            <strong>Hosting account not found on the server.</strong>
            Terminate and delete will remove only the platform record — no DirectAdmin API call is made.
            Use <strong>Delete record</strong> to clear this orphaned service.
        </div>
    @endif

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Service Details -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Service Details</h2>
                    <button @click="editDetailsModal = true" class="px-3 py-1.5 text-xs bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 font-medium rounded-lg transition inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        <span>Edit Details</span>
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Platform Status</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ ucfirst($service->status->value) }}</p>
                    </div>
                    @if ($service->status->value === 'suspended')
                        <div class="col-span-2">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Suspension Reason</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1">
                                {{ $enforcementInsight['suspension_message'] ?? 'Reason not recorded' }}
                            </p>
                        </div>
                    @endif
                    @if ($service->supportsLiveStatusProbe())
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Live Infrastructure</p>
                            <div class="mt-1">
                                @include('admin.services.partials.live-status-badge', ['service' => $service])
                            </div>
                            @if ($service->live_status_mismatch)
                                <p class="text-xs text-amber-600 dark:text-amber-400 mt-2">
                                    Platform status does not match what DirectAdmin or Docker reports. Use Refresh Status to re-check and heal provisioning drift.
                                </p>
                            @endif
                        </div>
                    @endif
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Billing Cycle</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ ucfirst($service->billing_cycle) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Next Due Date</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $service->next_due_date?->format('M d, Y') ?? '-' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $service->created_at->format('M d, Y') }}</p>
                    </div>
                    @if ($service->suspend_date)
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Suspended</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $service->suspend_date->format('M d, Y') }}</p>
                        </div>
                    @endif
                    @if ($service->terminate_date)
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Terminated</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $service->terminate_date->format('M d, Y') }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Configuration -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Configuration</h2>
                    @if ($service->status->value === 'provisioning')
                        <form method="POST" action="{{ route('admin.services.provision', $service) }}" class="inline">
                            @csrf
                            <button type="submit" class="px-3 py-1 text-xs bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-400 hover:bg-orange-200 dark:hover:bg-orange-900 font-medium rounded transition">
                                Retry Provisioning
                            </button>
                        </form>
                    @endif
                </div>
                <div class="space-y-3">
                    <!-- Provisioning Driver -->
                    @if ($service->provisioning_driver_key)
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Provisioning Driver</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $service->provisioning_driver_key }}</p>
                        </div>
                    @endif

                    <!-- Node (if assigned) -->
                    @if ($service->node_id)
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Node</p>
                            <div class="flex items-center justify-between mt-1">
                                <p class="text-sm text-slate-900 dark:text-white font-mono">{{ $service->node->name ?? 'Unknown' }}</p>
                                @if($service->node)
                                    <a href="{{ route('admin.nodes.show', $service->node) }}" class="text-xs text-blue-600 dark:text-blue-400 hover:underline">View Node</a>
                                @endif
                            </div>
                        </div>
                    @endif

                    <!-- External Reference -->
                    @if ($service->external_reference)
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">External Reference</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $service->external_reference }}</p>
                        </div>
                    @endif

                    <!-- Credentials (from credentials JSON field) -->
                    @if ($service->credentials && is_string($service->credentials))
                        @php
                            $creds = json_decode($service->credentials, true);
                        @endphp
                        @if ($creds)
                            @if (isset($creds['admin_username']) || isset($creds['username']))
                                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Username</p>
                                    <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $creds['admin_username'] ?? $creds['username'] ?? '-' }}</p>
                                </div>
                            @endif
                            @if (isset($creds['admin_email']) || isset($creds['access_url']))
                                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Admin Email</p>
                                    <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $creds['admin_email'] ?? $service->user->email ?? '-' }}</p>
                                </div>
                            @endif
                            @if (isset($creds['access_url']))
                                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Access URL</p>
                                    <p class="text-sm text-slate-900 dark:text-white font-mono mt-1 break-all">
                                        @if (filter_var($creds['access_url'], FILTER_VALIDATE_URL))
                                            <a href="{{ $creds['access_url'] }}" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">{{ $creds['access_url'] }}</a>
                                        @else
                                            {{ $creds['access_url'] }}
                                        @endif
                                    </p>
                                </div>
                            @endif
                            @if (isset($creds['port']))
                                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Port</p>
                                    <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $creds['port'] }}</p>
                                </div>
                            @endif
                        @endif
                    @endif
                </div>
            </div>

            <!-- DirectAdmin API Endpoint Debug Info -->
            @if ($service->node && $service->node->type === 'directadmin')
                <div class="bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900 rounded-2xl p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">🔧 DirectAdmin API Debug Info</h2>
                    <div class="space-y-3">
                        <div class="p-3 bg-white dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">API URL (Base)</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1 break-all">{{ $service->node->api_url ?? 'Not set' }}</p>
                        </div>

                        <div class="p-3 bg-white dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">API Endpoint (Suspend/Unsuspend)</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1 break-all">{{ ($service->node->api_url ?? 'NOT SET') }}/CMD_API_MODIFY_USER</p>
                        </div>

                        <div class="p-3 bg-white dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Admin Username</p>
                            <p class="text-sm mt-1">
                                @if($service->node->da_admin_username)
                                    <span class="text-slate-900 dark:text-white font-mono">{{ $service->node->da_admin_username }}</span>
                                @else
                                    <span class="text-red-600 dark:text-red-400 font-mono">⚠ NOT SET</span>
                                @endif
                            </p>
                        </div>

                        <div class="p-3 bg-white dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Login Key Status</p>
                            <p class="text-sm mt-1">
                                @if($service->node->da_login_key)
                                    <span class="text-emerald-600 dark:text-emerald-400">✓ Set</span>
                                @else
                                    <span class="text-red-600 dark:text-red-400">⚠ NOT SET</span>
                                @endif
                            </p>
                        </div>

                        <div class="p-3 bg-white dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Hosting Account Username</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $service->external_reference ?? $service->service_meta['username'] ?? '⚠ No username found' }}</p>
                        </div>

                        <div class="p-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-lg">
                            <p class="text-xs font-medium text-amber-800 dark:text-amber-300 mb-2">💡 Check laravel.log for detailed API request logs when testing</p>
                            <p class="text-xs text-amber-700 dark:text-amber-400">All API calls are logged with endpoint URL, status codes, and error details. Check <code>storage/logs/laravel.log</code> on the server.</p>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Service Metadata -->
            @if ($service->service_meta)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Service Metadata</h2>
                    <pre class="bg-slate-50 dark:bg-slate-800 p-4 rounded-lg text-xs text-slate-900 dark:text-slate-100 overflow-x-auto">{{ json_encode($service->service_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @endif

            <!-- Server Credentials (VPS / Dedicated Server) -->
            @if ($service->product && \App\Models\Product::isServerType($service->product->type) && $service->credentials)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Server Credentials</h2>
                        <form method="POST" action="{{ route('admin.services.resend-credentials', $service) }}" class="inline">
                            @csrf
                            <button type="submit" class="px-3 py-1 text-xs bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 font-medium rounded transition">
                                Resend Credentials
                            </button>
                        </form>
                    </div>
                    <div class="space-y-3">
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Username</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ json_decode($service->credentials)->username }}</p>
                        </div>
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Password</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ json_decode($service->credentials)->password }}</p>
                        </div>
                    </div>
                </div>
            @endif

            @php
                $hostingCredentials = $service->getHostingCredentials();
            @endphp
            @if ($service->isSharedHosting() && $hostingCredentials)
                @php
                    $directAdminAccount = $service->service_meta['directadmin_account'] ?? null;
                @endphp
                @if (is_array($directAdminAccount))
                    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">DirectAdmin Account</h2>
                            @if (!empty($directAdminAccount['checked_at']))
                                <span class="text-xs text-slate-500 dark:text-slate-400">Synced {{ \Carbon\Carbon::parse($directAdminAccount['checked_at'])->diffForHumans() }}</span>
                            @endif
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @if (!empty($directAdminAccount['application_stack']) || !empty($service->service_meta['application_stack']))
                                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Application stack</p>
                                    <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $directAdminAccount['application_stack'] ?? $service->service_meta['application_stack'] }}</p>
                                </div>
                            @endif
                            @if (!empty($directAdminAccount['database_template']) || !empty($service->service_meta['database_template_name']))
                                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Database type</p>
                                    <p class="text-sm text-slate-900 dark:text-white mt-1">{{ $directAdminAccount['database_template'] ?? $service->service_meta['database_template_name'] }}</p>
                                </div>
                            @endif
                            <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">MySQL databases</p>
                                <p class="text-sm text-slate-900 dark:text-white mt-1">
                                    {{ (int) ($directAdminAccount['counts']['database'] ?? 0) }}
                                    @if (!empty($directAdminAccount['counts']['database_limit']) && (int) $directAdminAccount['counts']['database_limit'] > 0)
                                        / {{ (int) $directAdminAccount['counts']['database_limit'] }}
                                    @endif
                                </p>
                                @if (!empty($directAdminAccount['databases']))
                                    <ul class="mt-2 space-y-1">
                                        @foreach ($directAdminAccount['databases'] as $databaseName)
                                            <li class="text-xs font-mono text-slate-600 dark:text-slate-300">{{ $databaseName }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                            <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Email accounts</p>
                                <p class="text-sm text-slate-900 dark:text-white mt-1">{{ (int) ($directAdminAccount['counts']['email'] ?? 0) }}</p>
                            </div>
                            <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Disk used (live)</p>
                                <p class="text-sm text-slate-900 dark:text-white mt-1">{{ number_format((float) ($directAdminAccount['disk_used_mb'] ?? 0), 1) }} MB</p>
                            </div>
                            <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Bandwidth used (live)</p>
                                <p class="text-sm text-slate-900 dark:text-white mt-1">{{ number_format((float) ($directAdminAccount['bandwidth_used_mb'] ?? 0), 1) }} MB</p>
                            </div>
                        </div>
                    </div>
                @endif
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">DirectAdmin Credentials</h2>
                        <form method="POST" action="{{ route('admin.services.resend-credentials', $service) }}" class="inline">
                            @csrf
                            <button type="submit" class="px-3 py-1 text-xs bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 font-medium rounded transition">
                                Resend Credentials
                            </button>
                        </form>
                    </div>
                    <div class="space-y-3">
                        @if (!empty($hostingCredentials['domain']))
                            <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Primary Domain</p>
                                <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $hostingCredentials['domain'] }}</p>
                            </div>
                        @endif
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Username</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $hostingCredentials['username'] }}</p>
                        </div>
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Password</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1">{{ $hostingCredentials['password'] }}</p>
                        </div>
                        @if (!empty($hostingCredentials['panel_url']))
                            <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Control Panel</p>
                                <a href="{{ $hostingCredentials['panel_url'] }}" target="_blank" rel="noopener noreferrer" class="text-sm text-blue-600 dark:text-blue-400 hover:underline font-mono mt-1 inline-block">{{ $hostingCredentials['panel_url'] }}</a>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Container Panel (if applicable) -->
            @include('admin.services.partials.container-panel')
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            @if ($service->isSharedHosting() || !empty($enforcementInsight['alerts']) || !empty($enforcementInsight['disk']) || !empty($enforcementInsight['database']))
                <x-service-enforcement-panel :insight="$enforcementInsight" />
            @endif

            <!-- Customer Info -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Customer</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold">
                            {{ strtoupper(substr($service->user->name, 0, 1)) }}
                        </div>
                        <div>
                            <x-admin.customer-link :user="$service->user" class="text-slate-900 dark:text-white" />
                            <p class="text-xs text-slate-600 dark:text-slate-400">{{ $service->user->email }}</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.customers.show', $service->user) }}" class="block mt-4 px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                        View Customer
                    </a>
                    <button type="button" @click="transferModal = true; transferPreview = null;" class="block w-full mt-2 px-4 py-2 bg-violet-100 dark:bg-violet-950 text-violet-700 dark:text-violet-300 hover:bg-violet-200 dark:hover:bg-violet-900 text-sm font-medium rounded-lg transition text-center">
                        Transfer Service
                    </button>
                </div>
            </div>

            <!-- Product Info -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Product</h3>
                <div class="space-y-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $service->product->name }}</p>
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">{{ ucfirst(str_replace('_', ' ', $service->product->type)) }}</p>
                    </div>
                    <a href="{{ route('admin.products.show', $service->product) }}" class="block px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                        View Product
                    </a>
                </div>
            </div>

            <!-- Related Invoice -->
            @if ($service->invoice)
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Invoice</h3>
                    <div class="space-y-3">
                        <p class="text-sm text-slate-900 dark:text-white">Invoice #{{ str_pad($service->invoice->id, 5, '0', STR_PAD_LEFT) }}</p>
                        <a href="{{ route('admin.invoices.show', $service->invoice) }}" class="block px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                            View Invoice
                        </a>
                    </div>
                </div>
            @endif

            <!-- Timeline -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Timeline</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-slate-900 dark:text-white">{{ $service->created_at->format('M d, Y \a\t h:i A') }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Last Updated</p>
                        <p class="text-slate-900 dark:text-white">{{ $service->updated_at->format('M d, Y \a\t h:i A') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Test Connection Result Modal -->
    <div x-show="testConnectionModal" x-transition
         class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
         @click.self="testConnectionModal = false">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl max-w-md w-full overflow-hidden">
            <!-- Header -->
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div x-show="testConnectionResult?.success" class="w-10 h-10 rounded-full bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center flex-shrink-0">
                        <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div x-show="!testConnectionResult?.success" class="w-10 h-10 rounded-full bg-red-100 dark:bg-red-950 flex items-center justify-center flex-shrink-0">
                        <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white" x-text="testConnectionResult?.success ? 'Connection Successful' : 'Connection Failed'"></h2>
                </div>
            </div>

            <!-- Body -->
            <div class="p-6 space-y-4">
                <div>
                    <p class="text-sm text-slate-700 dark:text-slate-300" x-text="testConnectionResult?.message || ''"></p>
                </div>

                <div x-show="testConnectionResult?.hint" class="p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                    <p class="text-xs font-medium text-amber-800 dark:text-amber-300 mb-1">Hint:</p>
                    <p class="text-xs text-amber-700 dark:text-amber-400" x-text="testConnectionResult?.hint || ''"></p>
                </div>

                <div x-show="testConnectionResult?.details" class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                    <p class="text-xs font-mono text-slate-600 dark:text-slate-400 break-words" x-text="testConnectionResult?.details || ''"></p>
                </div>
            </div>

            <!-- Footer -->
            <div class="p-6 border-t border-slate-200 dark:border-slate-800 flex gap-3">
                <button type="button" @click="testConnectionModal = false"
                        class="flex-1 px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white rounded-lg hover:bg-slate-200 dark:hover:bg-slate-700 font-medium transition">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Transfer Service Modal -->
    <div x-show="transferModal" x-transition
         class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
         @click.self="transferModal = false">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Transfer service</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                    Reassign service #{{ $service->id }} to another customer. Related invoices move with the service when they only bill this service.
                </p>
            </div>
            <form method="POST" action="{{ route('admin.services.transfer', $service) }}">
                @csrf
                <div class="p-6 space-y-4">
                    <div>
                        <label for="target_user_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">New customer</label>
                        <select id="target_user_id" name="target_user_id" required x-model="transferTargetId" @change="loadTransferPreview()"
                                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-violet-500 focus:border-transparent">
                            <option value="">Select a customer...</option>
                            @foreach ($transferCustomers as $customer)
                                <option value="{{ $customer->id }}" {{ old('target_user_id') == $customer->id ? 'selected' : '' }}>
                                    {{ $customer->name }} ({{ $customer->email }})
                                </option>
                            @endforeach
                        </select>
                        @error('target_user_id')
                            <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($service->attachedDomainName())
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" name="transfer_domain" value="1" x-model="transferDomain"
                                   class="mt-1 rounded border-slate-300 dark:border-slate-600 text-violet-600 focus:ring-violet-500">
                            <span class="text-sm text-slate-700 dark:text-slate-300">
                                Also transfer attached domain <code class="font-mono text-xs">{{ $service->attachedDomainName() }}</code> if owned by the current customer
                            </span>
                        </label>
                    @endif

                    <div x-show="transferPreviewLoading" class="text-sm text-slate-500 dark:text-slate-400">Loading preview...</div>

                    <div x-show="transferPreview?.error" class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-sm text-red-800 dark:text-red-200" x-text="transferPreview?.error"></div>

                    <div x-show="transferPreview && !transferPreview.error" class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 px-4 py-3 space-y-2 text-sm">
                        <p class="text-slate-900 dark:text-white">
                            <span class="font-medium">From:</span>
                            <span x-text="transferPreview?.from?.name"></span>
                            <span class="text-slate-500 dark:text-slate-400" x-show="transferPreview?.from?.reseller" x-text="'(' + transferPreview?.from?.reseller + ')'"></span>
                        </p>
                        <p class="text-slate-900 dark:text-white">
                            <span class="font-medium">To:</span>
                            <span x-text="transferPreview?.to?.name"></span>
                            <span class="text-slate-500 dark:text-slate-400" x-show="transferPreview?.to?.reseller" x-text="'(' + transferPreview?.to?.reseller + ')'"></span>
                        </p>
                        <template x-if="transferPreview?.warnings?.length">
                            <ul class="list-disc list-inside text-amber-700 dark:text-amber-300 space-y-1 pt-1">
                                <template x-for="warning in transferPreview.warnings" :key="warning">
                                    <li x-text="warning"></li>
                                </template>
                            </ul>
                        </template>
                    </div>
                </div>
                <div class="p-6 border-t border-slate-200 dark:border-slate-800 flex gap-3">
                    <button type="button" @click="transferModal = false"
                            class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium transition">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg font-medium transition"
                            :disabled="!transferTargetId">
                        Transfer service
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Suspend Service Modal -->
    <div x-show="suspendModal" x-transition
         class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
         @click.self="suspendModal = false">
        <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl max-w-lg w-full overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Suspend service</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Provide a reason so admins and support can see why this service was suspended.</p>
            </div>
            <form method="POST" action="{{ route('admin.services.suspend', $service) }}">
                @csrf
                <div class="p-6">
                    <label for="suspension_reason" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Suspension reason</label>
                    <textarea id="suspension_reason" name="suspension_reason" rows="4" required
                              class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-amber-500 focus:border-transparent"
                              placeholder="e.g. Unpaid invoice, policy violation, customer request">{{ old('suspension_reason') }}</textarea>
                </div>
                <div class="p-6 border-t border-slate-200 dark:border-slate-800 flex gap-3">
                    <button type="button" @click="suspendModal = false"
                            class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium transition">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-medium transition">
                        Suspend service
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Details Modal -->
    <div x-show="editDetailsModal" x-transition
         class="fixed inset-0 bg-black/50 z-50 flex items-end"
         @click.self="editDetailsModal = false">
        <div class="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-t-2xl shadow-2xl overflow-y-auto max-h-screen">
            <!-- Sticky Header -->
            <div class="sticky top-0 flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 z-10">
                <h2 class="text-xl font-bold text-slate-900 dark:text-white">Edit Service Details</h2>
                <button @click="editDetailsModal = false" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Form Content -->
            <form method="POST" action="{{ route('admin.services.update', $service) }}" class="p-6 space-y-6">
                @csrf
                @method('PUT')

                <!-- Billing Cycle -->
                <div>
                    <label for="billing_cycle" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing Cycle</label>
                    <select id="billing_cycle" name="billing_cycle"
                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            required>
                        <option value="monthly" @selected($service->billing_cycle === 'monthly')>Monthly</option>
                        <option value="quarterly" @selected($service->billing_cycle === 'quarterly')>Quarterly</option>
                        <option value="semi-annual" @selected($service->billing_cycle === 'semi-annual')>Semi-Annual</option>
                        <option value="annual" @selected($service->billing_cycle === 'annual')>Annual</option>
                    </select>
                </div>

                @if ($service->isSharedHosting())
                    <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 px-4 py-3 text-sm text-slate-600 dark:text-slate-400">
                        Current plan: <strong class="text-slate-900 dark:text-white">{{ $service->product->name }}</strong>.
                        To change the hosting package on DirectAdmin, use <button type="button" @click="editDetailsModal = false; upgradeHostingModal = true" class="text-emerald-600 dark:text-emerald-400 font-medium hover:underline">Upgrade Hosting</button>.
                    </div>
                @else
                    <!-- Product (non-hosting) -->
                    <div>
                        <label for="product_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product</label>
                        <select id="product_id" name="product_id"
                                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            @foreach($sameTypeProducts as $product)
                                <option value="{{ $product->id }}" @selected($service->product_id === $product->id)>
                                    {{ $product->name }}
                                    @if($product->monthly_price)
                                        — KES {{ number_format($product->monthly_price, 2) }}/mo
                                    @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <!-- Custom Price -->
                <div>
                    <label for="custom_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Custom Price</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-medium">{{ $currencyCode }}</span>
                        <input type="number" id="custom_price" name="custom_price" step="0.01" min="0"
                               value="{{ old('custom_price', $service->custom_price) }}"
                               class="w-full pl-14 pr-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               placeholder="Leave empty to use product price">
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        If set, overrides the product price for all renewal invoices.
                        @if($service->custom_price)
                            Current custom price: <strong>{{ $currencyCode }} {{ number_format($service->custom_price, 2) }}</strong>
                        @else
                            Currently using product price.
                        @endif
                    </p>
                </div>

                <!-- Next Due Date -->
                <div>
                    <label for="next_due_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Next Due Date</label>
                    <input type="date" id="next_due_date" name="next_due_date"
                           value="{{ $service->next_due_date?->format('Y-m-d') }}"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           required>
                </div>

                <!-- Commenced At -->
                <div>
                    <label for="commenced_at" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Commenced At</label>
                    <input type="date" id="commenced_at" name="commenced_at"
                           value="{{ $service->commenced_at?->format('Y-m-d') }}"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <!-- Suspend Date -->
                <div>
                    <label for="suspend_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Suspended</label>
                    <input type="date" id="suspend_date" name="suspend_date"
                           value="{{ $service->suspend_date?->format('Y-m-d') }}"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <!-- Terminate Date -->
                <div>
                    <label for="terminate_date" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Terminated</label>
                    <input type="date" id="terminate_date" name="terminate_date"
                           value="{{ $service->terminate_date?->format('Y-m-d') }}"
                           class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <!-- Notes -->
                <div>
                    <label for="notes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Notes</label>
                    <textarea id="notes" name="notes" rows="4"
                              class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                              placeholder="Add any notes about this service...">{{ $service->notes }}</textarea>
                </div>

                <!-- DirectAdmin Configuration (if shared hosting or can be configured) -->
                @if ($service->product && $service->product->type === 'shared_hosting')
                    <div class="border-t border-slate-200 dark:border-slate-600 pt-6 mt-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">DirectAdmin Configuration</h3>
                            <button type="button" @click="testDirectAdminConnection()"
                                    :disabled="testConnectionLoading"
                                    class="px-3 py-1 text-xs bg-cyan-100 dark:bg-cyan-950 text-cyan-700 dark:text-cyan-400 hover:bg-cyan-200 dark:hover:bg-cyan-900 disabled:opacity-50 disabled:cursor-not-allowed font-medium rounded transition inline-flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5a4 4 0 100-8 4 4 0 000 8z"/></svg>
                                <span x-text="testConnectionLoading ? 'Testing...' : 'Test Connection'"></span>
                            </button>
                        </div>

                        <!-- DirectAdmin Node -->
                        <div class="mb-4">
                            <label for="node_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">DirectAdmin Server</label>
                            <select id="node_id" name="node_id"
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">-- Select a DirectAdmin Server --</option>
                                @foreach(\App\Models\Node::where('type', 'directadmin')->where('is_active', true)->get() as $node)
                                    <option value="{{ $node->id }}" @selected($service->node_id === $node->id)>
                                        {{ $node->name }} ({{ $node->status }})
                                    </option>
                                @endforeach
                            </select>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Select the DirectAdmin server where the account exists</p>
                        </div>

                        <!-- Username -->
                        <div class="mb-4">
                            <label for="username" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Username</label>
                            @php
                                $creds = is_array($service->credentials) ? $service->credentials : (is_string($service->credentials) ? json_decode($service->credentials, true) : []);
                                $username = $creds['username'] ?? ($service->service_meta['username'] ?? '');
                            @endphp
                            <input type="text" id="username" name="username"
                                   value="{{ $username }}"
                                   maxlength="16"
                                   placeholder="DirectAdmin username"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Enter the exact username from your DirectAdmin server</p>
                        </div>

                        <!-- Password -->
                        <div class="mb-4">
                            <label for="password" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Password</label>
                            @php
                                $password = $creds['password'] ?? ($service->service_meta['password'] ?? '');
                            @endphp
                            <input type="text" id="password" name="password"
                                   value="{{ $password }}"
                                   placeholder="DirectAdmin password"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>

                        <!-- Primary Domain -->
                        <div class="mb-4">
                            <label for="primary_domain" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Primary Domain</label>
                            <input type="text" id="primary_domain" name="primary_domain"
                                   value="{{ $service->service_meta['domain'] ?? '' }}"
                                   placeholder="example.com"
                                   class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                @endif

                <!-- Form Actions (Sticky Footer) -->
                <div class="flex gap-3 pt-6 border-t border-slate-200 dark:border-slate-800 sticky bottom-0 bg-white dark:bg-slate-900">
                    <button type="button" @click="editDetailsModal = false"
                            class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium transition">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if ($service->isSharedHosting())
        <!-- Upgrade Hosting Modal -->
        <div x-show="upgradeHostingModal" x-transition
             class="fixed inset-0 bg-black/50 z-50 flex items-end"
             @click.self="upgradeHostingModal = false">
            <div class="bg-white dark:bg-slate-900 w-full max-w-2xl mx-auto rounded-t-2xl shadow-2xl overflow-y-auto max-h-[90vh]">
                <div class="sticky top-0 flex items-center justify-between p-6 border-b border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 z-10">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900 dark:text-white">Upgrade Hosting</h2>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                            Current plan: <strong>{{ $service->product->name }}</strong>
                            @if ($service->node)
                                on {{ $service->node->name }}
                            @endif
                        </p>
                    </div>
                    <button @click="upgradeHostingModal = false" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                @if (($planChangeOptions ?? collect())->isEmpty())
                    <div class="p-6 space-y-4">
                        <p class="text-sm text-slate-600 dark:text-slate-400">
                            No higher plans are available on this DirectAdmin server for this service.
                        </p>
                        <form method="POST" action="{{ route('admin.services.reconcile-hosting', $service) }}">
                            @csrf
                            <button type="submit" class="px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                                Refresh usage from DirectAdmin
                            </button>
                        </form>
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.services.upgrade-hosting', $service) }}" class="p-6 space-y-5" id="admin-plan-change-form">
                        @csrf
                        <input type="hidden" name="reseller_product_id" id="admin_reseller_product_id" value="">

                        @if (!empty($enforcementInsight['needs_upgrade']) || ($hasStaleOverlimitFlags ?? false))
                            <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
                                @if (!empty($enforcementInsight['database']['percent']) && !($enforcementInsight['database']['unlimited'] ?? false))
                                    Databases: {{ $enforcementInsight['database']['used'] ?? '?' }}/{{ $enforcementInsight['database']['limit'] ?? '?' }}
                                @endif
                                @if (!empty($enforcementInsight['disk']['percent']) && !($enforcementInsight['disk']['unlimited'] ?? false))
                                    <span class="block mt-1">Storage: {{ $enforcementInsight['disk']['percent'] }}% used</span>
                                @endif
                            </div>
                        @endif

                        <div class="space-y-3">
                            @foreach ($planChangeOptions as $option)
                                @php
                                    $product = $option['product'];
                                    $isRecommended = $recommendedOption
                                        && (int) ($recommendedOption['reseller_product_id'] ?? 0) === (int) ($option['reseller_product_id'] ?? 0)
                                        && (int) $recommendedOption['product']->id === (int) $product->id;
                                    $cycle = $service->billing_cycle ?? 'monthly';
                                    $upgradeService = app(\App\Services\Customer\CustomerHostingUpgradeService::class);
                                    $displayPrice = $upgradeService->displayPriceForPlanOption($service->user, $option, $cycle);
                                    $badge = match ($option['change_type']) {
                                        'downgrade' => 'Downgrade',
                                        'lateral' => 'Same tier',
                                        default => 'Upgrade',
                                    };
                                @endphp
                                <label class="flex items-start gap-3 p-4 border border-slate-200 dark:border-slate-700 rounded-xl hover:bg-emerald-50/50 dark:hover:bg-emerald-950/20 cursor-pointer has-[:checked]:ring-2 has-[:checked]:ring-emerald-500 {{ $isRecommended ? 'ring-2 ring-amber-400' : '' }}">
                                    <input type="radio" name="product_id" value="{{ $product->id }}" class="mt-1 admin-plan-option-radio" required
                                        data-reseller-product-id="{{ $option['reseller_product_id'] ?? '' }}"
                                        @checked($isRecommended)>
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <p class="font-semibold text-slate-900 dark:text-white">{{ $option['name'] }}</p>
                                            <span class="text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $badge }}</span>
                                            @if ($isRecommended)
                                                <span class="text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200">Recommended</span>
                                            @endif
                                        </div>
                                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                                            {{ $currencyCode }} {{ number_format($displayPrice, 0) }}/{{ $cycle === 'annual' ? 'yr' : 'mo' }}
                                            @if ($option['disk_quota'])
                                                · {{ rtrim(rtrim(number_format($option['disk_quota'], 2), '0'), '.') }} GB disk
                                            @endif
                                            @if ($option['num_databases'])
                                                · {{ $option['num_databases'] }} {{ \Illuminate\Support\Str::plural('database', $option['num_databases']) }}
                                            @endif
                                        </p>
                                    </div>
                                </label>
                            @endforeach
                        </div>

                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-white mb-2">Billing</p>
                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 mb-2">
                                <input type="radio" name="billing_action" value="apply_only" checked class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                Apply on DirectAdmin now (no invoice)
                            </label>
                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <input type="radio" name="billing_action" value="create_invoice" class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                Create prorated upgrade invoice for the customer
                            </label>
                        </div>

                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            The upgrade syncs the target package on DirectAdmin, updates platform limits, refreshes usage, and clears stale over-limit flags.
                        </p>

                        <div class="flex gap-3 pt-2 border-t border-slate-200 dark:border-slate-800">
                            <button type="button" @click="upgradeHostingModal = false"
                                    class="flex-1 px-4 py-2 border border-slate-300 dark:border-slate-600 text-slate-900 dark:text-white rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium transition">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="flex-1 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium transition">
                                Apply Plan Change
                            </button>
                        </div>
                    </form>
                    <script>
                        const adminResellerProductInput = document.getElementById('admin_reseller_product_id');
                        const syncAdminResellerProductId = () => {
                            const selected = document.querySelector('.admin-plan-option-radio:checked');
                            if (adminResellerProductInput) {
                                adminResellerProductInput.value = selected?.dataset.resellerProductId || '';
                            }
                        };
                        document.querySelectorAll('.admin-plan-option-radio').forEach((radio) => {
                            radio.addEventListener('change', syncAdminResellerProductId);
                        });
                        syncAdminResellerProductId();
                    </script>
                @endif
            </div>
        </div>
    @endif
</div>
@endsection
