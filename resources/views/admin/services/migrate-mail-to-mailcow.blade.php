@extends('layouts.admin')

@section('title', 'Migrate mail to Mailcow')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.services.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Services</a>
    <span class="text-slate-400">/</span>
    <a href="{{ route('admin.services.show', $service) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $service->name }}</a>
    <span class="text-slate-400">/</span>
    <span class="font-medium text-slate-600 dark:text-slate-400">Migrate mail</span>
</div>
@endsection

@section('content')
<div class="space-y-6 max-w-3xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Migrate mail to Mailcow</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">
            Creates an Email Hosting service, provisions the domain on Mailcow, and recreates mailboxes.
            Cut over MX after IMAP sync is healthy; keep DA mail until then.
        </p>
    </div>

    @if ($convertMeta)
        <div class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 p-4 text-sm">
            <p class="font-semibold">Last migration: {{ $convertMeta['status'] ?? 'n/a' }}</p>
            @if (!empty($convertMeta['email_service_id']))
                <p class="mt-1">Email service #{{ $convertMeta['email_service_id'] }}</p>
            @endif
            @if (!empty($convertMeta['note']))
                <p class="mt-1 text-slate-600">{{ $convertMeta['note'] }}</p>
            @endif
        </div>
    @endif

    @if (!empty($preflight['blockers']))
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold mb-2">Blockers</p>
            <ul class="list-disc pl-5 space-y-1">
                @foreach($preflight['blockers'] as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
            <div>
                <dt class="text-slate-500 text-xs uppercase">Domain</dt>
                <dd class="font-mono mt-1">{{ $preflight['domain'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500 text-xs uppercase">Mailboxes on DA</dt>
                <dd class="mt-1">{{ count($preflight['mailboxes'] ?? []) }}</dd>
            </div>
        </dl>

        @if (!empty($preflight['mailboxes']))
            <ul class="text-sm font-mono space-y-1 text-slate-600 dark:text-slate-300">
                @foreach($preflight['mailboxes'] as $box)
                    <li>{{ $box['email'] }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.services.migrate-mail.store', $service) }}" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1">Email product</label>
            <select name="product_id" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                @foreach($preflight['email_products'] as $product)
                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                @endforeach
            </select>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="create_sync_jobs" value="1" class="rounded">
            Create Mailcow IMAP sync jobs from DirectAdmin (requires IMAP password below)
        </label>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">DA IMAP host</label>
                <input type="text" name="da_imap_host" value="{{ old('da_imap_host', $service->node?->hostname) }}" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">DA mailbox password (shared)</label>
                <input type="password" name="da_imap_password" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" autocomplete="new-password">
                <p class="text-xs text-slate-500 mt-1">Only needed for sync jobs. Leave blank to create empty mailboxes (customer sets password).</p>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" @if(!($preflight['can_migrate'] ?? false)) disabled @endif
                class="px-4 py-2 bg-teal-600 hover:bg-teal-700 disabled:opacity-50 text-white rounded-lg text-sm font-medium">
                Migrate to Mailcow
            </button>
            <a href="{{ route('admin.services.show', $service) }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
