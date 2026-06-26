@extends('layouts.reseller')

@section('title', 'Customer Services')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Customer Services</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Services provisioned for your customers.</p>
    </div>

    <form method="GET" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 flex flex-wrap gap-4">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search service or customer..." class="flex-1 min-w-[200px] px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
        <select name="status" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
            <option value="all">All statuses</option>
            @foreach (['active','pending','suspended','terminated'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg">Filter</button>
    </form>

    @if ($services->count())
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Service</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Customer</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Status</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach ($services as $service)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800">
                            <td class="px-6 py-4">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $service->name }}</p>
                                <p class="text-xs text-slate-500">{{ $service->product?->name }}</p>
                            </td>
                            <td class="px-6 py-4 text-sm">{{ $service->user?->name }}</td>
                            <td class="px-6 py-4"><x-status-badge :status="$service->status" type="service" /></td>
                            <td class="px-6 py-4 text-right">
                                @include('reseller.services.partials.row-actions', ['service' => $service])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $services->links() }}
    @else
        <div class="p-12 text-center bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 text-slate-500">No services found.</div>
    @endif
</div>
@endsection
