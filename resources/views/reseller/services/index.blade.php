@extends('layouts.reseller')

@section('title', 'Customer Services')

@section('content')
@php
    $hasAny = $applicationServices->total() || $mailServices->total() || $otherServices->total();
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Customer Services</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Application hosting and email are listed separately so you can manage each stack on its own.</p>
    </div>

    <form method="GET" class="ui-card p-4 flex flex-wrap gap-4">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search service or customer..." class="flex-1 min-w-[200px] px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
        <select name="status" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
            <option value="all">All statuses</option>
            @foreach (['active','pending','provisioning','suspended','failed'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg">Filter</button>
    </form>

    @if (! $hasAny)
        <div class="p-12 text-center ui-card text-slate-500">No services found.</div>
    @else
        <section class="space-y-3">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Application services</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Container / application hosting sites.</p>
                </div>
                <span class="text-sm tabular-nums text-slate-500">{{ $applicationServices->total() }}</span>
            </div>
            @include('reseller.services.partials.service-table', [
                'paginator' => $applicationServices,
                'empty' => 'No application services match this filter.',
                'showUsage' => true,
                'usageByServiceId' => $applicationUsage ?? [],
            ])
        </section>

        <section class="space-y-3">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Mail services</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Email hosting mailboxes and domains.</p>
                </div>
                <span class="text-sm tabular-nums text-slate-500">{{ $mailServices->total() }}</span>
            </div>
            @include('reseller.services.partials.service-table', [
                'paginator' => $mailServices,
                'empty' => 'No mail services match this filter.',
            ])
        </section>

        @if ($otherServices->total() > 0)
            <section class="space-y-3">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Other services</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Shared, VPS, and anything that is not application or email hosting.</p>
                    </div>
                    <span class="text-sm tabular-nums text-slate-500">{{ $otherServices->total() }}</span>
                </div>
                @include('reseller.services.partials.service-table', [
                    'paginator' => $otherServices,
                    'empty' => 'No other services match this filter.',
                ])
            </section>
        @endif
    @endif
</div>
@endsection
