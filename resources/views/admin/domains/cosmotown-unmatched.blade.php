@extends('layouts.admin')

@section('title', 'Cosmotown unmatched domains')

@section('breadcrumb')
<div class="flex items-center gap-2">
    <a href="{{ route('admin.domains.index') }}" class="text-sm font-medium text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Domains</a>
    <span class="text-slate-400">/</span>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Cosmotown unmatched</p>
</div>
@endsection

@section('content')
<div class="space-y-6 max-w-5xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Cosmotown names not in Admin</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">
            These FQDNs are on the Cosmotown reseller account but have no customer domain row. Import attaches live expiry and nameservers to an existing customer. It does not create an invoice, order, or auto-renew.
        </p>
    </div>

    @if(empty($unmatched))
        <div class="ui-card p-6 text-sm text-slate-600 dark:text-slate-400">Every Cosmotown name already has an Admin → Domains row.</div>
    @else
        <div class="ui-card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-4 py-3 text-left">Domain</th>
                        <th class="px-4 py-3 text-left">Expires at Cosmotown</th>
                        <th class="px-4 py-3 text-left">Attach to customer</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach($unmatched as $row)
                        <tr>
                            <td class="px-4 py-3 font-mono">{{ $row['fqdn'] }}</td>
                            <td class="px-4 py-3">{{ $row['expires_at'] ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('admin.domains.cosmotown-import') }}" class="flex flex-col sm:flex-row gap-2 items-start sm:items-end">
                                    @csrf
                                    <input type="hidden" name="fqdn" value="{{ $row['fqdn'] }}">
                                    <div class="flex-1 min-w-[12rem]">
                                        <label class="block text-xs text-slate-500 mb-1">Customer</label>
                                        <select name="user_id" required class="w-full px-3 py-2 text-sm border rounded-lg bg-white dark:bg-slate-800 border-slate-300 dark:border-slate-600">
                                            <option value="">Select…</option>
                                            @foreach($customers as $customer)
                                                <option value="{{ $customer->id }}" @selected((int) old('user_id') === $customer->id)>{{ $customer->name }} ({{ $customer->email }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <label class="flex items-start gap-2 text-xs text-slate-600 dark:text-slate-300 max-w-xs">
                                        <input type="checkbox" name="confirm_no_invoice" value="1" class="mt-0.5 rounded" required>
                                        <span>Already at Cosmotown. Attach without an invoice.</span>
                                    </label>
                                    <button type="submit" class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Import</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
