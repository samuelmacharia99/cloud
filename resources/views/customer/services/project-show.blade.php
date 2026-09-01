@extends('layouts.customer')

@section('title', $project->name)

@section('content')
@php
    $billingService = $project->resolvedBillingService();
    $planLimits = $planUsage['limits'] ?? $project->includedPlanLimits();
    $canDeployIncluded = $project->canDeployIncludedWorkload();
    $canRemoveProject = $services->contains(fn ($s) => $s->isContainerHosting())
        && ! $services->contains(function ($s) {
            $status = $s->status->value ?? (string) $s->status;

            return ! $s->isContainerHosting() && ! in_array($status, ['terminated', 'cancelled'], true);
        });
    $primaryActionLabel = $canDeployIncluded ? 'Deploy new service' : 'Choose a plan';
    $nextDue = $billingService?->next_due_date;
    $consumption = $consumption ?? null;
    $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $barWidth = function (?float $percent): string {
        if ($percent === null) {
            return '0%';
        }

        return min(100, max(0, $percent)).'%';
    };
    $barTone = function (?float $percent): string {
        if ($percent === null) {
            return 'bg-ink-300 dark:bg-ink-600';
        }
        if ($percent >= 100) {
            return 'bg-red-500';
        }
        if ($percent >= 80) {
            return 'bg-amber-500';
        }

        return 'bg-brand-500';
    };
    $formatGb = fn (float $gb) => $trim($gb).' GB';
    $bytesToGb = fn (int $bytes) => $bytes / (1024 ** 3);
@endphp

<div
    class="space-y-6"
    x-data="{
        showRenameProject: false,
        showRemoveProject: false,
        renameProjectName: @js($project->name),
        confirmName: '',
    }"
>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('customer.services.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 dark:text-ink-400 hover:text-brand-600 dark:hover:text-brand-300 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                My Services
            </a>
            <div class="mt-3 flex items-start gap-3">
                <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-ink-950 ring-1 ring-brand-300/50 shadow-glow" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 7.5l-9-4.5-9 4.5m18 0l-9 4.5m9-4.5v9l-9 4.5m0-9L3 7.5m9 4.5v9m-9-13.5v9l9 4.5"/>
                    </svg>
                </span>
                <div class="min-w-0">
                    <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-ink-950 dark:text-white truncate" title="{{ $project->name }}">
                        {{ $project->name }}
                    </h1>
                    <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">
                        @if($billingService)
                            {{ $billingService->customerPlanName() }} <span class="text-ink-300 dark:text-ink-600">·</span> {{ $billingService->billing_cycle }}
                        @else
                            No plan yet — choose one to deploy your first service
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 shrink-0">
            <button
                type="button"
                class="btn-secondary btn-sm"
                @click="showRenameProject = true"
            >
                Rename
            </button>
            @if($canRemoveProject)
                <button
                    type="button"
                    class="btn-secondary btn-sm text-red-600 dark:text-red-400 hover:border-red-300 dark:hover:border-red-800"
                    @click="showRemoveProject = true"
                >
                    Remove project
                </button>
            @endif
            <a href="{{ route('customer.projects.deploy', $project) }}" class="btn-primary btn-sm">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14m-7-7h14"/>
                </svg>
                {{ $primaryActionLabel }}
            </a>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-1.5">
        @if($billingService)
            <x-status-badge :status="$billingService->status" type="service" />
        @endif
        <span class="status-pill bg-ink-100/90 dark:bg-white/10 text-ink-600 dark:text-ink-200">Owner</span>
        <span class="status-pill bg-ink-100/90 dark:bg-white/10 text-ink-600 dark:text-ink-200">{{ $project->resourceCount() }} {{ Str::plural('Resource', $project->resourceCount()) }}</span>
    </div>

    @if($planLimits)
        <div class="ui-card px-5 py-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-ink-950 dark:text-white">Plan consumption</h2>
                    <p class="mt-0.5 text-xs text-ink-500 dark:text-ink-400">
                        @if($consumption)
                            Average over the last {{ (int) ($consumption['window_hours'] ?? 6) }} hours
                            @if(! empty($consumption['sampled_at']))
                                · updated {{ \Illuminate\Support\Carbon::parse($consumption['sampled_at'])->timezone(config('app.timezone'))->format('M j, g:i A') }}
                            @endif
                        @else
                            Included plan resources
                        @endif
                    </p>
                </div>
                @if($consumption && ! ($consumption['has_samples'] ?? false))
                    <span class="status-pill bg-ink-100/90 dark:bg-white/10 text-ink-600 dark:text-ink-200">Waiting for metrics</span>
                @endif
            </div>

            @php
                $rows = [
                    [
                        'label' => 'vCPU',
                        'used' => $consumption ? $trim((float) ($consumption['cpu_cores'] ?? 0)) : '—',
                        'included' => $trim($planLimits['cpu']),
                        'percent' => $consumption['percent']['cpu'] ?? null,
                    ],
                    [
                        'label' => 'RAM',
                        'used' => $consumption ? $formatGb(((int) ($consumption['memory_mb'] ?? 0)) / 1024) : '—',
                        'included' => $formatGb($planLimits['memory_mb'] / 1024),
                        'percent' => $consumption['percent']['memory'] ?? null,
                    ],
                    [
                        'label' => 'Disk',
                        'used' => $consumption ? $formatGb((float) ($consumption['disk_gb'] ?? 0)) : '—',
                        'included' => $planLimits['disk_gb'] > 0 ? $formatGb($planLimits['disk_gb']) : '—',
                        'percent' => $consumption['percent']['disk'] ?? null,
                    ],
                ];
            @endphp

            <div class="mt-4 space-y-3.5">
                @foreach ($rows as $row)
                    <div>
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="font-medium text-ink-700 dark:text-ink-200">{{ $row['label'] }}</span>
                            <span class="tabular-nums text-ink-950 dark:text-white">
                                <span class="font-bold">{{ $row['used'] }}</span>
                                <span class="text-ink-400 dark:text-ink-500"> / {{ $row['included'] }} included</span>
                            </span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-ink-100 dark:bg-ink-800">
                            <div class="h-full rounded-full {{ $barTone($row['percent']) }}" style="width: {{ $barWidth($row['percent']) }}"></div>
                        </div>
                    </div>
                @endforeach

                @if($consumption)
                    @php
                        $includedBw = (float) ($consumption['included']['bandwidth_gb'] ?? 0);
                        $cycleGb = $bytesToGb((int) ($consumption['billing_transfer_bytes'] ?? 0));
                        $windowGb = $bytesToGb((int) ($consumption['transfer_bytes'] ?? 0));
                        $bwPercent = $consumption['percent']['bandwidth'] ?? null;
                    @endphp
                    <div>
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="font-medium text-ink-700 dark:text-ink-200">Transfer</span>
                            <span class="tabular-nums text-ink-950 dark:text-white">
                                <span class="font-bold">{{ $formatGb($cycleGb) }}</span>
                                <span class="text-ink-400 dark:text-ink-500">
                                    @if($includedBw > 0)
                                        / {{ $formatGb($includedBw) }} this cycle
                                    @else
                                        this cycle
                                    @endif
                                </span>
                            </span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-ink-100 dark:bg-ink-800">
                            <div class="h-full rounded-full {{ $barTone($bwPercent) }}" style="width: {{ $barWidth($bwPercent ?? ($includedBw > 0 ? null : 0)) }}"></div>
                        </div>
                        <p class="mt-1 text-[11px] text-ink-500 dark:text-ink-400">
                            {{ $formatGb($windowGb) }} in the last {{ (int) ($consumption['window_hours'] ?? 6) }} hours
                        </p>
                    </div>
                @endif
            </div>
            @if($nextDue)
                <p class="mt-3 flex items-center gap-1.5 border-t border-ink-200/70 dark:border-ink-700/60 pt-3 text-xs text-ink-500 dark:text-ink-400">
                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 5.25h15a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75V6a.75.75 0 01.75-.75z"/>
                    </svg>
                    Plan renews {{ $nextDue->format('M j, Y') }}
                </p>
            @endif
        </div>
        @if($planUsage && $canDeployIncluded)
            <p class="mt-3 text-xs text-ink-500 dark:text-ink-400">
                Next included deploy uses up to
                {{ $trim($planUsage['next_workload_share']['cpu'] * 100) }}% CPU /
                {{ $trim($planUsage['next_workload_share']['memory'] * 100) }}% RAM
                of the plan pool
                ({{ $trim($planUsage['remaining_cpu_share'] * 100) }}% CPU /
                {{ $trim($planUsage['remaining_memory_share'] * 100) }}% RAM still unallocated).
            </p>
        @endif
    @else
        <div class="ui-card px-5 py-4">
            <p class="text-sm text-ink-600 dark:text-ink-300">
                Pick a plan to start this project. Everything you deploy afterwards shares that one bill.
            </p>
        </div>
    @endif

    @if($billingService && $planLimits)
        <div class="rounded-xl border border-brand-200/70 dark:border-brand-900/60 bg-brand-50/70 dark:bg-brand-950/25 px-4 py-3">
            <p class="text-sm font-semibold text-ink-950 dark:text-white">
                {{ $billingService->customerPlanName() }} <span class="text-ink-400">·</span> <span class="capitalize font-medium">{{ $billingService->billing_cycle }}</span>
            </p>
            <p class="mt-1 text-xs text-ink-600 dark:text-ink-300">
                Extra services on this project are not billed again. Usage above the included
                {{ $trim($planLimits['cpu']) }} vCPU / {{ $trim($planLimits['memory_mb'] / 1024) }} GB RAM is metered on the next {{ $billingService->billing_cycle }} invoice.
            </p>
        </div>
    @endif

    <div>
        <div class="mb-4 flex items-center justify-between gap-3">
            <h2 class="font-display text-lg font-bold text-ink-950 dark:text-white">Services</h2>
            <a href="{{ route('customer.projects.deploy', $project) }}" class="text-sm font-semibold text-brand-600 dark:text-brand-400 hover:text-brand-700 dark:hover:text-brand-300">
                {{ $primaryActionLabel }} →
            </a>
        </div>

        @if($services->isNotEmpty())
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach ($services as $service)
                    @include('customer.services.partials.service-card', [
                        'service' => $service,
                        'allProjects' => $projects,
                        'nestedContainers' => (
                            $primaryContainer
                            && $service->id === $primaryContainer->id
                            && count($containers) >= 2
                        ) ? $containers : [],
                    ])
                @endforeach
            </div>
        @else
            <div class="ui-card rounded-xl border border-dashed border-ink-300/80 dark:border-ink-700/70 px-6 py-10 text-center">
                <p class="text-sm font-medium text-ink-700 dark:text-ink-200">No services here yet</p>
                <p class="mt-1 text-xs text-ink-500 dark:text-ink-400">
                    {{ $canDeployIncluded ? 'Deploy one on the plan above, or move an existing service here from My Services.' : 'Choose a plan to deploy your first service.' }}
                </p>
                <a href="{{ route('customer.projects.deploy', $project) }}" class="btn-primary btn-sm mt-4 inline-flex">
                    {{ $primaryActionLabel }}
                </a>
            </div>
        @endif
    </div>

    <div x-show="showRenameProject" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink-950/60 backdrop-blur-sm" @keydown.escape.window="showRenameProject = false">
        <div class="ui-card w-full max-w-md p-6" @click.outside="showRenameProject = false">
            <h3 class="font-display text-lg font-bold text-ink-950 dark:text-white">Rename project</h3>
            <p class="mt-1 text-sm text-ink-500 dark:text-ink-400">Your own label — billing and deployments are unchanged.</p>
            <form method="POST" action="{{ route('customer.projects.rename', $project) }}" class="mt-4 space-y-4">
                @csrf
                @method('PATCH')
                <input type="text" name="name" x-model="renameProjectName" required minlength="2" maxlength="100" class="w-full px-4 py-2.5">
                <div class="flex gap-2">
                    <button type="button" @click="showRenameProject = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                    <button type="submit" class="btn-primary flex-1 btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>

    @if($canRemoveProject)
        <div x-show="showRemoveProject" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink-950/60 backdrop-blur-sm" @keydown.escape.window="showRemoveProject = false">
            <div class="ui-card w-full max-w-md p-6" @click.outside="showRemoveProject = false">
                <h3 class="font-display text-lg font-bold text-ink-950 dark:text-white">Remove project</h3>
                <p class="mt-1 text-sm text-ink-600 dark:text-ink-300">
                    This permanently deletes every Application Hosting site in <strong>{{ $project->name }}</strong>, including containers and files. This cannot be undone. Email Hosting is not deleted this way.
                </p>
                <form method="POST" action="{{ route('customer.projects.destroy', $project) }}" class="mt-4 space-y-4">
                    @csrf
                    @method('DELETE')
                    <label class="block text-sm font-medium text-ink-700 dark:text-ink-200">
                        Type <span class="font-mono">{{ $project->name }}</span> to confirm
                        <input type="text" name="confirm_name" x-model="confirmName" required autocomplete="off" class="mt-1.5 w-full px-4 py-2.5">
                    </label>
                    <label class="flex items-start gap-2 text-sm text-ink-700 dark:text-ink-200">
                        <input type="checkbox" name="confirm" value="1" required class="mt-1 rounded">
                        <span>I understand these Application Hosting sites and their files will be permanently deleted.</span>
                    </label>
                    <div class="flex gap-2">
                        <button type="button" @click="showRemoveProject = false" class="btn-secondary flex-1 btn-sm">Keep project</button>
                        <button
                            type="submit"
                            class="btn btn-sm flex-1 bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-500 disabled:opacity-40"
                            :disabled="confirmName !== @js($project->name)"
                        >Delete sites</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
@endsection
