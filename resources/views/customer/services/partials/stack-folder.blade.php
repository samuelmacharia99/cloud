@php
    /** @var \App\Services\Customer\StackFolder $folder */
    $service = $folder->anchor;
    $canRenew = in_array($service->status->value, ['active', 'suspended'], true);
    $payInvoice = $service->unpaidActivationInvoice();
    $manageUrl = $payInvoice ? route('customer.payment.select-method', $payInvoice) : route('customer.services.show', $service);
    $isWordpress = $service->isWordPressContainer();
    $allProjects = $allProjects ?? collect();
    $deployment = $service->containerDeployment;
    $stackRunning = $deployment && $deployment->isRunning();
    $stackStoppable = $deployment && in_array($deployment->status, ['stopped', 'failed'], true);
    $roleChips = $folder->services
        ->map(fn ($s) => ($s->service_meta ?: [])['project_role_label'] ?? null)
        ->filter()->unique()->values();
@endphp

<section
    class="ui-card overflow-hidden rounded-2xl border border-ink-200/80 dark:border-ink-700/60"
    data-stack-folder="{{ $folder->key }}"
    x-data="{
        showRename: false,
        showMove: false,
        showNewProject: false,
        renameName: @js($service->customerServiceName()),
        newProjectName: '',
    }"
>
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-ink-100 dark:border-ink-800/80 px-4 py-3.5">
        <div class="flex min-w-0 items-start gap-3">
            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 dark:bg-brand-950/40 text-brand-600 dark:text-brand-300" aria-hidden="true">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>
            </span>
            <div class="min-w-0">
                <h3 class="truncate text-base font-semibold text-ink-950 dark:text-white">{{ $folder->name }}</h3>
                <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-ink-500 dark:text-ink-400">
                    <span>{{ $service->customerPlanName() }}</span>
                    <span class="text-ink-300 dark:text-ink-600">·</span>
                    <span class="capitalize">{{ $service->billing_cycle }}</span>
                    @foreach($roleChips as $chip)
                        <span class="rounded-md bg-ink-100/80 dark:bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-ink-600 dark:text-ink-300">{{ $chip }}</span>
                    @endforeach
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <x-status-badge :status="$service->status" type="service" />
            @if($folder->isDeployed())
                <x-container-state-pill :state="$folder->aggregateState" :label="$folder->aggregateLabel()" :class="$folder->stale ? 'opacity-70' : ''" />
            @endif
            <span class="status-pill bg-ink-100/90 dark:bg-white/10 text-ink-600 dark:text-ink-200">{{ $folder->memberCount() }} {{ Str::plural('container', $folder->memberCount()) }}</span>
        </div>
    </header>

    <ul class="divide-y divide-ink-100 dark:divide-ink-800/80" data-stack-members>
        @foreach($folder->members as $member)
            @include('customer.services.partials.stack-member-row', ['member' => $member, 'folder' => $folder])
        @endforeach
    </ul>

    @if($folder->isDeployed() && $folder->isSynthesized())
        <p class="border-t border-ink-100 dark:border-ink-800/80 px-4 py-2 text-[11px] text-ink-500 dark:text-ink-400">The stack definition could not be read; showing what the deployment declares.</p>
    @elseif(! $folder->isDeployed())
        <p class="border-t border-ink-100 dark:border-ink-800/80 px-4 py-2 text-[11px] text-ink-500 dark:text-ink-400">Waiting for the first deployment.</p>
    @elseif($folder->lastError)
        <p class="border-t border-ink-100 dark:border-ink-800/80 px-4 py-2 text-[11px] text-amber-700 dark:text-amber-300">Last check could not reach the host: {{ Str::limit($folder->lastError, 120) }}</p>
    @endif

    <footer class="flex flex-wrap items-center gap-2 border-t border-ink-100 dark:border-ink-800/80 bg-ink-50/70 dark:bg-ink-950/30 px-4 py-3">
        @if($folder->isDeployed() && $canRenew)
            @if($stackRunning)
                <form method="POST" action="{{ route('customer.services.container.restart', $service) }}" data-confirm="Restart the whole stack? Every container in it restarts.">
                    @csrf
                    <button type="submit" class="btn-secondary btn-sm">Restart stack</button>
                </form>
                <form method="POST" action="{{ route('customer.services.container.stop', $service) }}" data-confirm="Stop the whole stack? The application goes offline until you start it again.">
                    @csrf
                    <button type="submit" class="btn-secondary btn-sm">Stop</button>
                </form>
            @elseif($stackStoppable)
                <form method="POST" action="{{ route('customer.services.container.start', $service) }}">
                    @csrf
                    <button type="submit" class="btn-primary btn-sm">Start</button>
                </form>
            @endif
        @endif

        <span class="flex-1"></span>

        <a href="{{ $manageUrl }}" class="{{ $payInvoice ? 'btn-primary' : 'btn-secondary' }} btn-sm" draggable="false">{{ $payInvoice ? 'Pay' : 'Manage' }}</a>
        @if($canRenew)
            <a href="{{ route('customer.services.renew', $service) }}" class="btn-secondary btn-sm" draggable="false">Renew</a>
        @endif
        <button type="button" @click="showRename = true" class="btn-secondary btn-sm">Rename</button>
        @if($isWordpress)
            <a href="{{ route('customer.services.wordpress-admin', $service) }}" class="btn-primary btn-sm" draggable="false">WP Admin</a>
        @else
            <button type="button" @click="showMove = true" class="btn-secondary btn-sm">Move</button>
        @endif
    </footer>

    @include('customer.services.partials.service-modals', ['service' => $service, 'allProjects' => $allProjects, 'isWordpress' => $isWordpress])
</section>
