@php
    $contents = $mailDomainContents ?? null;
    $currentDomain = $contents['domain'] ?? null;
    $countsReadable = (bool) ($contents['readable'] ?? false);
    $countsSentence = $countsReadable
        ? ', with '.(int) ($contents['mailboxes'] ?? 0).' mailbox(es) and '.(int) ($contents['aliases'] ?? 0).' alias(es) on it'
        : '';
    $infoPassword = session('mail_info_password');
    $infoMailbox = session('mail_info_mailbox');
@endphp
<div class="ui-card p-6 space-y-4" x-data="{ open: false, showPassword: false }">
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Mail domain</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                @if ($currentDomain)
                    This plan hosts mail for <span class="font-mono">{{ $currentDomain }}</span>{{ $countsSentence }}.
                @else
                    This plan has no mail domain yet.
                @endif
            </p>
            @if (($contents['readable'] ?? true) === false)
                <p class="text-xs text-amber-700 dark:text-amber-300 mt-1">Mailcow did not answer, so what is on the domain could not be counted: {{ $contents['message'] }}</p>
            @endif
        </div>
        <button type="button" class="px-3 py-1.5 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg text-sm"
                @click="open = !open" x-text="open ? 'Cancel' : 'Change mail domain'"></button>
    </div>

    @if ($infoPassword && $infoMailbox)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 dark:bg-emerald-950/40 p-4 space-y-2">
            <p class="text-sm font-medium text-emerald-900 dark:text-emerald-100">Password for {{ $infoMailbox }}. It is shown only once; give it to the customer now.</p>
            <div class="flex items-center gap-3 flex-wrap">
                <code class="px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-emerald-200 text-sm font-mono" x-text="showPassword ? @js($infoPassword) : '•'.repeat(16)"></code>
                <button type="button" class="text-xs font-medium text-emerald-800 dark:text-emerald-200 underline" @click="showPassword = !showPassword" x-text="showPassword ? 'Hide' : 'Show'"></button>
                <button type="button" class="text-xs font-medium text-emerald-800 dark:text-emerald-200 underline" @click="navigator.clipboard.writeText(@js($infoPassword))">Copy</button>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.services.mail-domain.replace', $service) }}" class="space-y-3" x-show="open" x-cloak>
        @csrf
        <div class="rounded-xl border border-red-200 bg-red-50 dark:bg-red-950/30 p-4 text-sm text-red-800 dark:text-red-200">
            Mail cannot be moved to a new address. Saving deletes
            @if ($currentDomain)
                <span class="font-mono">{{ $currentDomain }}</span> on the mail server, with every mailbox, alias and message on it,
            @else
                the current mail domain
            @endif
            and there is no copy afterwards. The new domain is created on this plan's limits with a fresh <span class="font-mono">info@</span> inbox.
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium mb-1" for="mail-domain-new">New mail domain</label>
                <input id="mail-domain-new" name="domain" required value="{{ old('domain') }}" placeholder="school.co.ke"
                       class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm font-mono">
                @error('domain')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            @if ($currentDomain)
                <div>
                    <label class="block text-sm font-medium mb-1" for="mail-domain-confirm">Type {{ $currentDomain }} to confirm</label>
                    <input id="mail-domain-confirm" name="confirm_current_domain" required placeholder="{{ $currentDomain }}"
                           class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm font-mono">
                    @error('confirm_current_domain')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
            @endif
        </div>
        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="understood" value="1" required class="mt-1">
            <span>I understand the mail on the current domain is destroyed and cannot be recovered.</span>
        </label>
        <button class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium">Replace mail domain</button>
    </form>
</div>
