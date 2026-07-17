@extends('layouts.customer')

@section('title', 'Email: ' . $service->product->name)

@section('content')
<div class="space-y-6" x-data="{ tab: 'mailboxes' }">
    <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $service->product->name }}</h1>
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
            <a href="{{ route('customer.services.index') }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm text-slate-700 dark:text-slate-200">
                All services
            </a>
        </div>
    </div>

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

    <div class="border-b border-slate-200 dark:border-slate-800 flex gap-6 overflow-x-auto">
        <button type="button" @click="tab='mailboxes'" :class="tab==='mailboxes' ? 'border-b-2 border-teal-600 text-slate-900 dark:text-white' : 'text-slate-500'" class="px-2 py-3 text-sm font-medium whitespace-nowrap">Mailboxes</button>
        <button type="button" @click="tab='aliases'" :class="tab==='aliases' ? 'border-b-2 border-teal-600 text-slate-900 dark:text-white' : 'text-slate-500'" class="px-2 py-3 text-sm font-medium whitespace-nowrap">Aliases</button>
        <button type="button" @click="tab='dns'" :class="tab==='dns' ? 'border-b-2 border-teal-600 text-slate-900 dark:text-white' : 'text-slate-500'" class="px-2 py-3 text-sm font-medium whitespace-nowrap">DNS</button>
        <button type="button" @click="tab='connect'" :class="tab==='connect' ? 'border-b-2 border-teal-600 text-slate-900 dark:text-white' : 'text-slate-500'" class="px-2 py-3 text-sm font-medium whitespace-nowrap">Connect</button>
    </div>

    <div x-show="tab==='mailboxes'" class="space-y-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
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
                            <th class="py-2"></th>
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
                                <td class="py-3 text-right">
                                    <form method="POST" action="{{ route('customer.services.email.mailboxes.destroy', $service) }}" onsubmit="return confirm('Delete this mailbox?')">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="email" value="{{ $email }}">
                                        <button class="text-red-600 hover:underline text-xs">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-slate-500">No mailboxes yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
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
                <div>
                    <label class="block text-sm font-medium mb-1">Password</label>
                    <input type="password" name="password" required minlength="8" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-sm">
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

    <div x-show="tab==='aliases'" x-cloak class="space-y-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
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

    <div x-show="tab==='dns'" x-cloak class="space-y-6">
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
                <div>
                    <h2 class="font-semibold text-lg">DNS checklist</h2>
                    <p class="text-sm text-slate-500 mt-1">Point MX at Mailcow. If this domain uses Talksasa Cloudflare DNS, you can apply records automatically.</p>
                </div>
                <form method="POST" action="{{ route('customer.services.email.dns.apply', $service) }}">
                    @csrf
                    <button class="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-sm">Apply via Cloudflare</button>
                </form>
            </div>
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
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-3 text-sm">
            <h2 class="font-semibold text-lg mb-2">Client settings</h2>
            <p><span class="text-slate-500">IMAP:</span> <span class="font-mono">{{ $connection['imap_host'] ?? '—' }}:{{ $connection['imap_port'] ?? 993 }}</span> (SSL/TLS)</p>
            <p><span class="text-slate-500">SMTP:</span> <span class="font-mono">{{ $connection['smtp_host'] ?? '—' }}:{{ $connection['smtp_port'] ?? 587 }}</span> (STARTTLS) or <span class="font-mono">:{{ $connection['smtp_ssl_port'] ?? 465 }}</span> (SSL)</p>
            <p><span class="text-slate-500">Username:</span> full email address</p>
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
