@extends('layouts.customer')

@section('title', 'Domain transfer approval')

@section('content')
<div class="max-w-lg mx-auto space-y-6">
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Domain transfer request</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-2 text-sm">
            <strong class="font-mono text-slate-900 dark:text-white">{{ $domain->name }}{{ $domain->extension }}</strong>
            is being transferred from <strong>{{ $domain->user?->name }}</strong> to your account.
        </p>

        <div class="flex flex-wrap gap-3 mt-8">
            <form method="POST" action="{{ route('customer.domains.inter-transfer.approve', $token) }}">
                @csrf
                <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-medium text-sm">Approve transfer</button>
            </form>
            <form method="POST" action="{{ route('customer.domains.inter-transfer.reject', $token) }}" data-confirm="Reject this domain transfer?">
                @csrf
                <button type="submit" class="px-5 py-2.5 border border-red-300 text-red-600 rounded-lg font-medium text-sm">Reject</button>
            </form>
        </div>
    </div>
</div>
@endsection
