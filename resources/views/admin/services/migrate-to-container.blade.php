@extends('layouts.admin')

@section('title', 'Convert to App Hosting')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.services.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Services</a>
    <span class="text-slate-400">/</span>
    <a href="{{ route('admin.services.show', $service) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $service->name }}</a>
    <span class="text-slate-400">/</span>
    <span class="font-medium text-slate-600 dark:text-slate-400">Convert to App Hosting</span>
</div>
@endsection

@section('content')
<div class="space-y-6 max-w-3xl">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Convert to App Hosting</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">
            Admin-only, convert-in-place. Same service ID — no second service, no invoice, no customer notification.
            Keeps DirectAdmin due date; next renewal uses the App Hosting price. Email stays on DirectAdmin.
        </p>
    </div>

    @if ($convertMeta)
        <div class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 p-4 text-sm">
            <p class="font-semibold">Last convert status: <span class="uppercase">{{ $convertMeta['status'] ?? 'n/a' }}</span></p>
            @if (!empty($convertMeta['error']))
                <p class="text-red-600 mt-1">{{ $convertMeta['error'] }}</p>
            @endif
            @if (!empty($convertMeta['steps']) && is_array($convertMeta['steps']))
                <ul class="mt-2 font-mono text-xs space-y-1 text-slate-600 dark:text-slate-300">
                    @foreach ($convertMeta['steps'] as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($preflightError)
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $preflightError }}</div>
    @elseif ($preflight)
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
            <h2 class="font-semibold text-lg">Preflight</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-slate-500">Customer</dt>
                    <dd class="font-medium">{{ $service->user?->email ?? ('#'.$service->user_id) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Detected stack</dt>
                    <dd class="font-medium capitalize">{{ str_replace('_', ' ', $preflight['inventory']['stack']) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Domain</dt>
                    <dd class="font-mono">{{ $preflight['inventory']['domain'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Docroot</dt>
                    <dd class="font-mono text-xs break-all">{{ $preflight['inventory']['docroot'] }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Keep due date</dt>
                    <dd class="font-medium">{{ $currentDue?->format('Y-m-d') ?? '—' }} · {{ $currentCycle }}</dd>
                </div>
            </dl>

            <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-4 space-y-2">
                <h3 class="text-sm font-semibold">Email inventory</h3>
                @if (!($preflight['email']['success'] ?? false))
                    <p class="text-sm text-red-600">{{ $preflight['email']['message'] ?? 'Failed' }}</p>
                @else
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Default (DA username) mailboxes: {{ count($preflight['email']['default_mailboxes']) }}
                        · Extra mailboxes: {{ count($preflight['email']['extra_mailboxes']) }}
                    </p>
                    @if ($preflight['email']['has_extra_mailboxes'])
                        <ul class="text-xs font-mono text-amber-800 dark:text-amber-200 space-y-1">
                            @foreach ($preflight['email']['extra_mailboxes'] as $box)
                                <li>{{ $box['email'] }}</li>
                            @endforeach
                        </ul>
                        <p class="text-sm text-amber-800 dark:text-amber-200">
                            Extra mailboxes detected — DirectAdmin must keep serving mail (MX unchanged). You must acknowledge below.
                        </p>
                    @else
                        <p class="text-sm text-emerald-700 dark:text-emerald-300">Only default-style mailbox(es) found. Email can remain on DA after web cutover.</p>
                    @endif
                @endif
            </div>

            @if (!empty($preflight['blockers']))
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 space-y-1">
                    @foreach ($preflight['blockers'] as $blocker)
                        <p>{{ $blocker }}</p>
                    @endforeach
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.services.migrate-to-container.store', $service) }}" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium mb-2">WordPress App Hosting product (billing at next renewal)</label>
                <select name="product_id" required class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2" @disabled(!($preflight['can_convert'] ?? false))>
                    @foreach ($preflight['wordpress_products'] as $product)
                        <option value="{{ $product->id }}">
                            {{ $product->name }}
                            — next renewal ≈ KES {{ number_format($productEstimates[$product->id] ?? 0, 0) }}
                            / {{ $currentCycle }}
                        </option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-500 mt-2">No charge today. <code class="font-mono">custom_price</code> is cleared so renewals use this product’s retail price. Due date stays {{ $currentDue?->format('Y-m-d') ?? 'unchanged' }}.</p>
            </div>

            @if (!empty($preflight['inventory']['databases']))
                <div>
                    <label class="block text-sm font-medium mb-2">Source database (optional)</label>
                    <select name="database_name" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 font-mono text-sm">
                        <option value="">Auto from wp-config / inventory</option>
                        @foreach ($preflight['inventory']['databases'] as $db)
                            <option value="{{ $db['name'] }}">{{ $db['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($preflight['email']['has_extra_mailboxes'] ?? false)
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="acknowledge_extra_mailboxes" value="1" class="mt-1 rounded border-slate-300" required>
                    <span>I acknowledge extra mailboxes stay on DirectAdmin; only the website moves to the container.</span>
                </label>
            @endif

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="confirm_silent" value="1" class="mt-1 rounded border-slate-300" required>
                <span>Confirm: silent admin convert — no invoice, no customer email/SMS, one service row.</span>
            </label>

            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium disabled:opacity-50" @disabled(!($preflight['can_convert'] ?? false))>
                Queue silent convert
            </button>
        </form>
    @endif
</div>
@endsection
