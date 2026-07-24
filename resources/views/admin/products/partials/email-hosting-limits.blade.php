@php
    $limits = $limits ?? [];
    $defaultMailboxes = (int) config('mailcow.default_mailboxes', 10);
    $defaultAliases = (int) config('mailcow.default_aliases', 20);
    $defaultQuotaMb = (int) config('mailcow.default_quota_mb', 51200);
    $defaultMailboxQuotaMb = (int) config('mailcow.default_mailbox_quota_mb', 5120);

    $quotaGb = old(
        'resource_limits.quota_gb',
        isset($limits['quota_mb']) ? round(((float) $limits['quota_mb']) / 1024, 2) : round($defaultQuotaMb / 1024, 2)
    );
    $mailboxQuotaGb = old(
        'resource_limits.mailbox_quota_gb',
        isset($limits['mailbox_quota_mb']) ? round(((float) $limits['mailbox_quota_mb']) / 1024, 2) : round($defaultMailboxQuotaMb / 1024, 2)
    );
@endphp

<div class="border-t border-slate-200 dark:border-slate-800 pt-6" x-show="productType === 'email_hosting'" x-cloak>
    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Email plan limits</h3>
    <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
        These limits are provisioned to Mailcow for each domain on this plan.
    </p>

    <input type="hidden" name="provisioning_driver_key" value="mailcow" :disabled="productType !== 'email_hosting'">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label for="email_mailboxes" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Mailboxes</label>
            <input
                type="number"
                id="email_mailboxes"
                name="resource_limits[mailboxes]"
                value="{{ old('resource_limits.mailboxes', $limits['mailboxes'] ?? $defaultMailboxes) }}"
                min="1"
                step="1"
                placeholder="e.g. 10"
                :disabled="productType !== 'email_hosting'"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-teal-500 dark:focus:ring-teal-400 text-slate-900 dark:text-white text-sm @error('resource_limits.mailboxes') border-red-500 @enderror"
            >
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Max email accounts per domain</p>
            @error('resource_limits.mailboxes')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="email_aliases" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Aliases</label>
            <input
                type="number"
                id="email_aliases"
                name="resource_limits[aliases]"
                value="{{ old('resource_limits.aliases', $limits['aliases'] ?? $defaultAliases) }}"
                min="0"
                step="1"
                placeholder="e.g. 20"
                :disabled="productType !== 'email_hosting'"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-teal-500 dark:focus:ring-teal-400 text-slate-900 dark:text-white text-sm @error('resource_limits.aliases') border-red-500 @enderror"
            >
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Max aliases / forwards per domain</p>
            @error('resource_limits.aliases')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="email_quota_gb" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Total storage (GB)</label>
            <input
                type="number"
                id="email_quota_gb"
                name="resource_limits[quota_gb]"
                value="{{ $quotaGb }}"
                min="0.1"
                step="0.1"
                placeholder="e.g. 50"
                :disabled="productType !== 'email_hosting'"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-teal-500 dark:focus:ring-teal-400 text-slate-900 dark:text-white text-sm @error('resource_limits.quota_gb') border-red-500 @enderror"
            >
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Combined quota for the whole domain</p>
            @error('resource_limits.quota_gb')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="email_mailbox_quota_gb" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Per-mailbox max (GB)</label>
            <input
                type="number"
                id="email_mailbox_quota_gb"
                name="resource_limits[mailbox_quota_gb]"
                value="{{ $mailboxQuotaGb }}"
                min="0.1"
                step="0.1"
                placeholder="e.g. 5"
                :disabled="productType !== 'email_hosting'"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-teal-500 dark:focus:ring-teal-400 text-slate-900 dark:text-white text-sm @error('resource_limits.mailbox_quota_gb') border-red-500 @enderror"
            >
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Default / max size for a single mailbox</p>
            @error('resource_limits.mailbox_quota_gb')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>
    </div>
</div>
