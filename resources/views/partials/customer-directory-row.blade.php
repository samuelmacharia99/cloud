@props([
    'row',
    'context' => 'reseller',
])

@php
    $user = $row['user'] ?? null;
    $isLinked = ($row['link_status'] ?? '') === 'linked';
    $billingStatus = $row['billing_status'] ?? 'needs_package';
    $matchedListing = $row['matched_listing'] ?? null;
    $statusLabel = $user?->status
        ? ucfirst((string) $user->status)
        : (($row['da_status'] ?? null) === 'suspended' ? 'Suspended (DA)' : 'Active (DA)');
    $statusClass = ($user?->status === 'suspended' || ($row['da_status'] ?? null) === 'suspended')
        ? 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300'
        : (($user?->status ?? 'active') === 'inactive'
            ? 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400'
            : 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300');
@endphp

<tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
    <td class="px-6 py-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br {{ $context === 'admin' ? 'from-blue-400 to-blue-600' : 'from-purple-400 to-purple-600' }} flex items-center justify-center text-white text-sm font-semibold">
                {{ strtoupper(substr($row['display_name'] ?? '?', 0, 1)) }}
            </div>
            <div>
                @if ($user)
                    @if ($context === 'admin')
                        <x-admin.customer-link :user="$user" />
                    @else
                        <p class="font-medium text-slate-900 dark:text-white">{{ $row['display_name'] }}</p>
                    @endif
                @else
                    <p class="font-medium text-slate-900 dark:text-white">{{ $row['display_name'] }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">DirectAdmin only</p>
                @endif
                <p class="text-xs text-slate-600 dark:text-slate-400">{{ $row['display_email'] ?: 'No email on file' }}</p>
            </div>
        </div>
    </td>

    @if ($context === 'admin')
        <td class="px-6 py-4">
            @if (! empty($row['reseller']))
                <a href="{{ route('admin.resellers.show', $row['reseller']) }}" class="font-medium text-purple-700 dark:text-purple-300 hover:underline text-sm">
                    {{ $row['reseller']->name }}
                </a>
            @else
                <span class="text-sm text-slate-600 dark:text-slate-400">Platform</span>
            @endif
        </td>
    @endif

    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400 font-mono">
        {{ $row['da_username'] ?: '—' }}
    </td>
    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
        {{ $row['da_domain'] ?: '—' }}
    </td>
    <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
        {{ $row['da_package'] ?: '—' }}
    </td>
    <td class="px-6 py-4">
        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $isLinked ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300' }}">
            {{ $isLinked ? 'Linked' : 'Unlinked' }}
        </span>
    </td>
    <td class="px-6 py-4">
        @if ($billingStatus === 'ready')
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">Auto-billing ready</span>
        @elseif ($billingStatus === 'package_detected' && $matchedListing)
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300" title="Matches catalog item {{ $matchedListing->name }}">
                Package detected
            </span>
        @else
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400">Needs package</span>
        @endif
    </td>
    <td class="px-6 py-4">
        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusClass }}">
            {{ $statusLabel }}
        </span>
    </td>
    @if ($context === 'admin')
        <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
            {{ $user?->company ?: '—' }}
        </td>
        <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
            {{ $user?->country ? \App\Support\Countries::display($user->country) : '—' }}
        </td>
    @else
        <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
            {{ $user?->company ?: '—' }}
        </td>
    @endif
    <td class="px-6 py-4 text-sm text-slate-900 dark:text-white font-medium">
        {{ $row['services_count'] ?? 0 }}
    </td>
    <td class="px-6 py-4 text-sm text-slate-900 dark:text-white font-medium">
        {{ $row['invoices_count'] ?? 0 }}
    </td>
    <td class="px-6 py-4 text-right">
        @if ($user && $context === 'reseller')
            <div class="flex items-center justify-end gap-1">
                <form method="POST" action="{{ route('reseller.customers.impersonate', $user) }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition" title="Login as this customer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                        </svg>
                    </button>
                </form>
                <a href="{{ route('reseller.customers.show', $user) }}" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded-lg transition" title="View customer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                </a>
                <a href="{{ route('reseller.customers.edit', $user) }}" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition" title="Edit customer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                </a>
                <form method="POST" action="{{ route('reseller.customers.destroy', $user) }}" class="inline" data-confirm='Are you sure you want to delete this customer?'>
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition" title="Delete customer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </button>
                </form>
            </div>
        @elseif ($user && $context === 'admin')
            <div class="flex items-center justify-end gap-1">
                <a href="{{ route('admin.customers.show', $user) }}" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded-lg transition">View</a>
            </div>
        @else
            <span class="text-xs text-slate-500 dark:text-slate-400">Add on platform to manage</span>
        @endif
    </td>
</tr>
