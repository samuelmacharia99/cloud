@extends('layouts.customer')

@section('title', 'Email: ' . $service->customerPlanName())

@section('content')
<div class="space-y-6" x-data="{ tab: @js(request('tab', 'mailboxes')) }">
    <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $service->customerPlanName() }}</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">
                Mail domain:
                <span class="font-mono text-slate-900 dark:text-white">{{ $mailDomain ?? '—' }}</span>
                · Service #{{ $service->id }}
            </p>
        </div>
        <div class="flex gap-2 flex-wrap">
            @if (!empty($connection['webmail_url']))
                <a href="{{ $connection['webmail_url'] }}" target="_blank" rel="noopener"
                   class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white font-medium rounded-lg transition text-sm">
                    Open webmail
                </a>
            @endif
            <a href="{{ route('customer.email.inboxes') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm text-slate-700 dark:text-slate-200">
                All inboxes
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 dark:bg-emerald-950/40 p-4 text-sm text-emerald-900 dark:text-emerald-100">
            {{ session('success') }}
        </div>
    @endif

    @if (session('info'))
        <div class="rounded-xl border border-blue-200 bg-blue-50 dark:bg-blue-950/40 p-4 text-sm text-blue-900 dark:text-blue-100">
            {{ session('info') }}
        </div>
    @endif

    @if ($error)
        <div class="rounded-xl border border-amber-200 bg-amber-50 dark:bg-amber-950/40 p-4 text-sm text-amber-900 dark:text-amber-100">
            {{ $error }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            {{ $errors->first() }}
        </div>
    @endif

    @if (!empty($health))
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Mailboxes</p>
                <p class="text-lg font-semibold mt-1">{{ $health['mailbox_count'] }} / {{ $health['mailbox_limit'] }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Messages / day</p>
                <p class="text-lg font-semibold mt-1">{{ number_format($limits['msgs_per_day'] ?? $health['msgs_per_day'] ?? 0) }}</p>
            </div>
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">DNS / auth</p>
                <p class="text-sm font-medium mt-1 {{ ($health['dns_ok'] ?? null) === true ? 'text-emerald-700 dark:text-emerald-300' : (($health['dns_ok'] ?? null) === false ? 'text-amber-700 dark:text-amber-300' : 'text-slate-700 dark:text-slate-300') }}">
                    {{ $health['dns_note'] }}
                </p>
            </div>
        </div>
        @if (!empty($health['auth_checks']))
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @foreach (['mx' => 'MX', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $key => $label)
                    @php
                        $okKey = $key.'_ok';
                        $ok = $health[$okKey] ?? null;
                        $tone = $ok === true ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100' : ($ok === false ? 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100' : 'border-slate-200 bg-white text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300');
                    @endphp
                    <div class="rounded-xl border p-3 {{ $tone }}">
                        <p class="text-xs font-semibold uppercase tracking-wide opacity-80">{{ $label }}</p>
                        <p class="text-xs mt-1 leading-snug">{{ $health['auth_checks'][$key] ?? '—' }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    <div class="border-b border-slate-200 dark:border-slate-800 flex gap-6 overflow-x-auto">
        <button type="button" @click="tab='mailboxes'" :class="tab==='mailboxes' ? 'border-b-2 border-teal-600 text-slate-900 dark:text-white' : 'text-slate-500'" class="px-2 py-3 text-sm font-medium whitespace-nowrap">Mailboxes</button>
        <button type="button" @click="tab='manage'" :class="tab==='manage' ? 'border-b-2 border-teal-600 text-slate-900 dark:text-white' : 'text-slate-500'" class="px-2 py-3 text-sm font-medium whitespace-nowrap">Manage</button>
        <button type="button" @click="tab='aliases'" :class="tab==='aliases' ? 'border-b-2 border-teal-600 text-slate-900 dark:text-white' : 'text-slate-500'" class="px-2 py-3 text-sm font-medium whitespace-nowrap">Aliases</button>
        <button type="button" @click="tab='delivery'" :class="tab==='delivery' ? 'border-b-2 border-teal-600 text-slate-900 dark:text-white' : 'text-slate-500'" class="px-2 py-3 text-sm font-medium whitespace-nowrap">Test delivery</button>
        <button type="button" @click="tab='dns'" :class="tab==='dns' ? 'border-b-2 border-teal-600 text-slate-900 dark:text-white' : 'text-slate-500'" class="px-2 py-3 text-sm font-medium whitespace-nowrap">DNS</button>
        <button type="button" @click="tab='connect'" :class="tab==='connect' ? 'border-b-2 border-teal-600 text-slate-900 dark:text-white' : 'text-slate-500'" class="px-2 py-3 text-sm font-medium whitespace-nowrap">Connect</button>
    </div>

    <div x-show="tab==='mailboxes'" class="space-y-6">
        <div class="ui-card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-semibold text-lg">Mailboxes</h2>
                <p class="text-sm text-slate-500">{{ count($mailboxes) }} / {{ $limits['mailboxes'] }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-200 dark:border-slate-700">
                            <th class="py-2 pr-4">Email</th>
                            <th class="py-2 pr-4">Name</th>
                            <th class="py-2 pr-4">Quota</th>
                            <th class="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($mailboxes as $mailbox)
                            @php
                                $email = $mailbox['username'] ?? $mailbox['email'] ?? ($mailbox['local_part'] ?? '').'@'.($mailDomain ?? '');
                            @endphp
                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                <td class="py-3 pr-4 font-mono">{{ $email }}</td>
                                <td class="py-3 pr-4">{{ $mailbox['name'] ?? '—' }}</td>
                                <td class="py-3 pr-4">{{ $mailbox['quota'] ?? $mailbox['quota_used'] ?? '—' }}</td>
                                <td class="py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        <form method="POST" action="{{ route('customer.services.email.mailboxes.open', $service) }}" target="_blank">
                                            @csrf
                                            <input type="hidden" name="email" value="{{ $email }}">
                                            <button class="text-teal-700 dark:text-teal-300 hover:underline text-xs font-medium">Open mailbox</button>
                                        </form>
                                        <form method="POST" action="{{ route('customer.services.email.mailboxes.destroy', $service) }}" onsubmit="return confirm('Delete this mailbox?')">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="email" value="{{ $email }}">
                                            <button class="text-red-600 hover:underline text-xs">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-slate-500">No mailboxes yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ui-card p-6">
            <h2 class="font-semibold text-lg mb-4">Create mailbox</h2>
            <form method="POST" action="{{ route('customer.services.email.mailboxes.store', $service) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium mb-1">Local part</label>
                    <div class="flex items-center gap-2">
                        <input name="local_part" value="{{ old('local_part') }}" required class="flex-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" placeholder="info">
                        <span class="text-sm text-slate-500 font-mono">{{ '@'.$mailDomain }}</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Display name</label>
                    <input name="name" value="{{ old('name') }}" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                </div>
                <div x-data="{ showPassword: false }">
                    <label class="block text-sm font-medium mb-1">Password</label>
                    <div class="relative">
                        <input
                            :type="showPassword ? 'text' : 'password'"
                            name="password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            class="w-full px-3 py-2 pr-10 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm"
                        >
                        <button
                            type="button"
                            @click="showPassword = !showPassword"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200"
                            :title="showPassword ? 'Hide password' : 'Show password'"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                        >
                            <svg x-show="!showPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg x-show="showPassword" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m4.753-4.753L3.596 3.596m16.807 16.807L6.404 6.404m9.596 9.596a3 3 0 10-4.242-4.242m4.242 4.242L9.172 9.172"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Quota (MB)</label>
                    <input type="number" name="quota_mb" value="{{ old('quota_mb', $limits['mailbox_quota_mb']) }}" min="100" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                </div>
                <div class="md:col-span-2">
                    <button class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Create mailbox</button>
                </div>
            </form>
        </div>
    </div>

    <div x-show="tab==='manage'" x-cloak class="space-y-6">
        <div class="ui-card p-6">
            <h2 class="font-semibold text-lg mb-1">Change mailbox password</h2>
            <p class="text-sm text-slate-500 mb-4">Reset a mailbox password without opening SOGo.</p>
            @if (count($mailboxes) === 0)
                <p class="text-sm text-slate-500">Create a mailbox first.</p>
            @else
                <form method="POST" action="{{ route('customer.services.email.mailboxes.password', $service) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium mb-1">Mailbox</label>
                        <select name="email" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                            @foreach($mailboxes as $mailbox)
                                @php $email = $mailbox['username'] ?? $mailbox['email'] ?? ($mailbox['local_part'] ?? '').'@'.($mailDomain ?? ''); @endphp
                                <option value="{{ $email }}">{{ $email }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div x-data="{ showPassword: false }">
                        <label class="block text-sm font-medium mb-1">New password</label>
                        <div class="relative">
                            <input
                                :type="showPassword ? 'text' : 'password'"
                                name="password"
                                required
                                minlength="8"
                                autocomplete="new-password"
                                class="w-full px-3 py-2 pr-10 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm"
                            >
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200"
                                :title="showPassword ? 'Hide password' : 'Show password'"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            >
                                <svg x-show="!showPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <svg x-show="showPassword" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m4.753-4.753L3.596 3.596m16.807 16.807L6.404 6.404m9.596 9.596a3 3 0 10-4.242-4.242m4.242 4.242L9.172 9.172"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div x-data="{ showPassword: false }">
                        <label class="block text-sm font-medium mb-1">Confirm password</label>
                        <div class="relative">
                            <input
                                :type="showPassword ? 'text' : 'password'"
                                name="password_confirmation"
                                required
                                minlength="8"
                                autocomplete="new-password"
                                class="w-full px-3 py-2 pr-10 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm"
                            >
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200"
                                :title="showPassword ? 'Hide password' : 'Show password'"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            >
                                <svg x-show="!showPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <svg x-show="showPassword" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m4.753-4.753L3.596 3.596m16.807 16.807L6.404 6.404m9.596 9.596a3 3 0 10-4.242-4.242m4.242 4.242L9.172 9.172"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <button class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Update password</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="ui-card p-6">
            <h2 class="font-semibold text-lg mb-1">Display name</h2>
            <p class="text-sm text-slate-500 mb-4">Shown as the From name in outgoing mail.</p>
            @if (count($mailboxes) === 0)
                <p class="text-sm text-slate-500">Create a mailbox first.</p>
            @else
                <form method="POST" action="{{ route('customer.services.email.mailboxes.name', $service) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium mb-1">Mailbox</label>
                        <select name="email" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                            @foreach($mailboxes as $mailbox)
                                @php $email = $mailbox['username'] ?? $mailbox['email'] ?? ($mailbox['local_part'] ?? '').'@'.($mailDomain ?? ''); @endphp
                                <option value="{{ $email }}">{{ $email }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Display name</label>
                        <input name="name" required maxlength="120" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" placeholder="Acme Support">
                    </div>
                    <div class="md:col-span-2">
                        <button class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Save name</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="ui-card p-6">
            <h2 class="font-semibold text-lg mb-1">Out of office</h2>
            <p class="text-sm text-slate-500 mb-4">Auto-reply for a mailbox. Uses a Mailcow sieve filter managed from Talksasa.</p>
            @if (count($mailboxes) === 0)
                <p class="text-sm text-slate-500">Create a mailbox first.</p>
            @else
                <form method="POST" action="{{ route('customer.services.email.mailboxes.vacation.enable', $service) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium mb-1">Mailbox</label>
                        <select name="email" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                            @foreach($mailboxes as $mailbox)
                                @php $email = $mailbox['username'] ?? $mailbox['email'] ?? ($mailbox['local_part'] ?? '').'@'.($mailDomain ?? ''); @endphp
                                <option value="{{ $email }}">{{ $email }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Repeat every (days)</label>
                        <input type="number" name="days" value="1" min="1" max="30" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium mb-1">Subject</label>
                        <input name="subject" required value="{{ old('subject', 'Out of office') }}" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium mb-1">Message</label>
                        <textarea name="body" required rows="4" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm" placeholder="Thanks for your email. I am away and will reply when I return.">{{ old('body') }}</textarea>
                    </div>
                    <div class="md:col-span-2 flex flex-wrap gap-3">
                        <button class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Enable out of office</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('customer.services.email.mailboxes.vacation.disable', $service) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4" onsubmit="return confirm('Disable out-of-office for the selected mailbox?')">
                    @csrf
                    @method('DELETE')
                    <div>
                        <label class="block text-sm font-medium mb-1">Mailbox to disable</label>
                        <select name="email" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                            @foreach($mailboxes as $mailbox)
                                @php $email = $mailbox['username'] ?? $mailbox['email'] ?? ($mailbox['local_part'] ?? '').'@'.($mailDomain ?? ''); @endphp
                                <option value="{{ $email }}">{{ $email }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm">Disable</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <div x-show="tab==='aliases'" x-cloak class="space-y-6">
        <div class="ui-card p-6">
            <h2 class="font-semibold text-lg mb-4">Aliases</h2>
            <div class="overflow-x-auto mb-6">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-200 dark:border-slate-700">
                            <th class="py-2 pr-4">Address</th>
                            <th class="py-2 pr-4">Goes to</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($aliases as $alias)
                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                <td class="py-3 pr-4 font-mono">{{ $alias['address'] ?? $alias['local_part'] ?? '—' }}</td>
                                <td class="py-3 pr-4 font-mono text-xs">{{ is_array($alias['goto'] ?? null) ? implode(', ', $alias['goto']) : ($alias['goto'] ?? '—') }}</td>
                                <td class="py-3 text-right">
                                    @if (!empty($alias['id']))
                                        <form method="POST" action="{{ route('customer.services.email.aliases.destroy', $service) }}" onsubmit="return confirm('Delete alias?')">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="id" value="{{ $alias['id'] }}">
                                            <button class="text-red-600 hover:underline text-xs">Delete</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-6 text-slate-500">No aliases yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <form method="POST" action="{{ route('customer.services.email.aliases.store', $service) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium mb-1">Alias address</label>
                    <input name="address" placeholder="sales@{{ $mailDomain }}" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Forward to</label>
                    <input name="goto" placeholder="you@elsewhere.com" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                </div>
                <div class="md:col-span-2">
                    <button class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Create alias</button>
                </div>
            </form>
        </div>
    </div>

    <div x-show="tab==='delivery'" x-cloak class="space-y-6">
        <div class="ui-card p-6">
            <h2 class="font-semibold text-lg mb-1">Test delivery</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
                Send a real message from one of your mailboxes to verify outbound SMTP. Uses a temporary app password — your mailbox password is not required.
            </p>
            @if (count($mailboxes) === 0)
                <p class="text-sm text-slate-500">Create a mailbox first, then run a delivery test.</p>
            @else
                <form method="POST" action="{{ route('customer.services.email.test-delivery', $service) }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <label class="block text-sm font-medium mb-1">Send from</label>
                        <select name="from" required class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                            @foreach($mailboxes as $mailbox)
                                @php
                                    $email = $mailbox['username'] ?? $mailbox['email'] ?? ($mailbox['local_part'] ?? '').'@'.($mailDomain ?? '');
                                @endphp
                                <option value="{{ $email }}" @selected(old('from') === $email)>{{ $email }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Send to</label>
                        <input type="email" name="to" value="{{ old('to', auth()->user()->email) }}" required placeholder="you@elsewhere.com" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
                    </div>
                    <div class="md:col-span-2">
                        <button class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium">Send test message</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <div x-show="tab==='dns'" x-cloak class="space-y-6">
        <div class="ui-card p-6">
            <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
                <div>
                    <h2 class="font-semibold text-lg">DNS checklist</h2>
                    <p class="text-sm text-slate-500 mt-1">Publish MX, SPF, DKIM, and DMARC. If this domain uses Talksasa Cloudflare DNS, apply records automatically.</p>
                </div>
                <form method="POST" action="{{ route('customer.services.email.dns.apply', $service) }}">
                    @csrf
                    <button class="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-sm">Apply via Cloudflare</button>
                </form>
            </div>
            @if (!empty($health['ptr_note']))
                <p class="text-xs text-slate-500 mb-4">{{ $health['ptr_note'] }}</p>
            @endif
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-200 dark:border-slate-700">
                            <th class="py-2 pr-4">Type</th>
                            <th class="py-2 pr-4">Name</th>
                            <th class="py-2 pr-4">Value</th>
                            <th class="py-2">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dnsRecords as $record)
                            <tr class="border-b border-slate-100 dark:border-slate-800 align-top">
                                <td class="py-3 pr-4 font-mono">{{ $record['type'] }}@if(!empty($record['priority'])) ({{ $record['priority'] }})@endif</td>
                                <td class="py-3 pr-4 font-mono">{{ $record['name'] }}</td>
                                <td class="py-3 pr-4 font-mono text-xs break-all">{{ $record['content'] }}</td>
                                <td class="py-3 text-slate-500">{{ $record['note'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div x-show="tab==='connect'" x-cloak>
        <div class="ui-card p-6 space-y-3 text-sm">
            <h2 class="font-semibold text-lg mb-2">Client settings</h2>
            <p><span class="text-slate-500">IMAP:</span> <span class="font-mono">{{ $connection['imap_host'] ?? '—' }}:{{ $connection['imap_port'] ?? 993 }}</span> (SSL/TLS)</p>
            <p><span class="text-slate-500">SMTP:</span> <span class="font-mono">{{ $connection['smtp_host'] ?? '—' }}:{{ $connection['smtp_port'] ?? 587 }}</span> (STARTTLS) or <span class="font-mono">:{{ $connection['smtp_ssl_port'] ?? 465 }}</span> (SSL)</p>
            <p><span class="text-slate-500">Username:</span> full email address</p>
            <p><span class="text-slate-500">Daily send limit:</span> {{ number_format($limits['msgs_per_day'] ?? 0) }} messages / day for this domain</p>
            <p><span class="text-slate-500">Webmail:</span>
                @if (!empty($connection['webmail_url']))
                    <a class="text-teal-600 hover:underline font-mono" href="{{ $connection['webmail_url'] }}" target="_blank" rel="noopener">{{ $connection['webmail_url'] }}</a>
                @else
                    —
                @endif
            </p>
        </div>
    </div>
</div>
@endsection
