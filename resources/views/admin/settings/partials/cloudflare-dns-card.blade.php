<form method="POST" action="{{ route('admin.settings.update') }}" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6" @submit.prevent="window.submitForm($el)">
    @csrf

    <fieldset>
        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-2">Cloudflare DNS</legend>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
            Managed DNS for domain-only customers and application hosting. Uses your Cloudflare account with branded nameservers.
        </p>
        <div class="mb-4 rounded-lg border border-amber-200 dark:border-amber-800/60 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-100">
            <p class="font-medium mb-1">API token permissions (required)</p>
            <ul class="list-disc pl-5 space-y-1 text-amber-800 dark:text-amber-200/90">
                <li><strong>Zone → Zone → Edit</strong> (account-wide — needed to <em>create</em> zones; “specific zone only” is not enough)</li>
                <li><strong>Zone → DNS → Edit</strong></li>
                <li><strong>Account → Account Settings → Read</strong> (for Test connection)</li>
                <li>Account resources: your Talksasa Cloudflare account · Zone resources: <strong>All zones</strong> from that account</li>
            </ul>
            <p class="mt-2 text-xs text-amber-700 dark:text-amber-300/80">
                “Edit zone DNS” templates often cannot create zones and will fail Enable DNS with <code class="font-mono">account.zone.create</code>.
            </p>
        </div>

        <div class="space-y-4">
            <div>
                <input type="hidden" name="settings[cloudflare_enabled]" value="0">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="settings[cloudflare_enabled]" value="1" @checked(in_array($settings['cloudflare_enabled'] ?? 'false', ['1', 'true'], true)) class="rounded" />
                    <span class="text-slate-700 dark:text-slate-300">Enable Cloudflare DNS management for customers</span>
                </label>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">API Token</label>
                    <input
                        type="password"
                        name="settings[cloudflare_api_token]"
                        value=""
                        placeholder="{{ ! empty($settings['cloudflare_api_token']) ? '•••••••• (leave blank to keep)' : 'Paste new API token' }}"
                        autocomplete="new-password"
                        class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm"
                    />
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        Paste the full token once, then Save. Leave blank to keep the current token.
                        Do not include the word <code class="font-mono">Bearer</code>. Account ID must match the account this token can create zones in.
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Account ID</label>
                    <input type="text" name="settings[cloudflare_account_id]" value="{{ $settings['cloudflare_account_id'] ?? '' }}" placeholder="Cloudflare account ID" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm" />
                </div>
            </div>

            <div>
                <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Branded nameservers</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">Set these in Cloudflare custom nameservers, then enter them here for domain registration.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach (['cloudflare_branded_ns1' => 'NS1', 'cloudflare_branded_ns2' => 'NS2', 'cloudflare_branded_ns3' => 'NS3 (optional)', 'cloudflare_branded_ns4' => 'NS4 (optional)'] as $key => $label)
                        <div>
                            <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">{{ $label }}</label>
                            <input type="text" name="settings[{{ $key }}]" value="{{ old($key, in_array(($settings[$key] ?? ''), ['0', '-', 'ns.example.com'], true) ? '' : ($settings[$key] ?? '')) }}" placeholder="{{ str_contains($label, 'optional') ? 'leave blank' : 'ns.example.com' }}" class="block w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm" />
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">Leave NS3/NS4 empty unless you have real extra nameservers. Do not enter <code class="font-mono">0</code> or placeholders.</p>
            </div>
        </div>
    </fieldset>

    <div class="pt-4 border-t border-slate-200 dark:border-slate-800 flex flex-wrap items-center justify-between gap-3">
        <p id="cloudflare-test-result" class="text-sm"></p>
        <div class="flex items-center gap-3">
            <button type="button" id="cloudflare-test-btn" class="px-4 py-2.5 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                Test connection
            </button>
            <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                Save Cloudflare DNS
            </button>
        </div>
    </div>
</form>

<script>
document.getElementById('cloudflare-test-btn')?.addEventListener('click', async function () {
    const resultEl = document.getElementById('cloudflare-test-result');
    resultEl.textContent = 'Testing…';
    resultEl.className = 'text-sm text-slate-500';

    try {
        const res = await fetch('{{ route('admin.settings.test-cloudflare') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
        });
        const data = await res.json();
        resultEl.textContent = data.message || (data.success ? 'Connected.' : 'Connection failed.');
        resultEl.className = data.success
            ? 'text-sm text-emerald-600 dark:text-emerald-400'
            : 'text-sm text-red-600 dark:text-red-400';
    } catch (e) {
        resultEl.textContent = 'Request failed.';
        resultEl.className = 'text-sm text-red-600 dark:text-red-400';
    }
});
</script>
