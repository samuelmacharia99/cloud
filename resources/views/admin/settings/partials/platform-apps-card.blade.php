<form method="POST" action="{{ route('admin.settings.update') }}" class="ui-card p-8 space-y-6" @submit.prevent="window.submitForm($el)">
    @csrf

    <fieldset>
        <legend class="text-lg font-semibold text-slate-900 dark:text-white mb-2">Platform app hostnames</legend>
        <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">
            Every application stack gets <code class="font-mono">{name}.{zone}</code> the moment it deploys, with an A record on this zone and TLS from one wildcard certificate per container host. Leave the zone empty to keep the feature off.
        </p>
        <div class="mb-4 rounded-lg border border-amber-200 dark:border-amber-800/60 bg-amber-50 dark:bg-amber-950/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-100">
            <p class="font-medium mb-1">Before enabling</p>
            <ul class="list-disc pl-5 space-y-1 text-amber-800 dark:text-amber-200/90">
                <li>The zone (for example <code class="font-mono">apps.example.com</code>) must live in the Cloudflare account the Cloudflare DNS token above can edit. Records are managed with that token.</li>
                <li>The DNS token below is written to each container host for certificate issuance. Create a <strong>separate</strong> token with <strong>Zone → DNS → Edit</strong> on this one zone only.</li>
                <li>Container hosts need <code class="font-mono">python3-certbot-dns-cloudflare</code>; it is installed on first use when missing.</li>
            </ul>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Apps zone</label>
                <input type="text" name="settings[platform_apps_zone]" value="{{ $settings['platform_apps_zone'] ?? '' }}" placeholder="apps.example.com" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm" />
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Stacks become <code class="font-mono">user-1-service-10.apps.example.com</code>. Customers cannot edit or remove these hostnames.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Cloudflare zone ID</label>
                <input type="text" name="settings[platform_apps_cloudflare_zone_id]" value="{{ $settings['platform_apps_cloudflare_zone_id'] ?? '' }}" placeholder="Zone ID of the registered domain" class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm" />
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">The zone that holds the registered domain, shown on the Cloudflare overview page.</p>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">DNS token for certificate issuance</label>
                <input
                    type="password"
                    name="settings[platform_apps_dns_api_token]"
                    value=""
                    placeholder="{{ ($configuredSensitiveSettings['platform_apps_dns_api_token'] ?? false) ? '•••••••• (leave blank to keep)' : 'Paste a Zone → DNS → Edit token scoped to this zone' }}"
                    autocomplete="new-password"
                    class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm"
                />
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Stored encrypted and copied to each container host as <code class="font-mono">/etc/letsencrypt/talksasa-apps-cloudflare.ini</code> (mode 600). Leave blank to keep the current token.</p>
            </div>
        </div>
    </fieldset>

    <div class="pt-4 border-t border-slate-200 dark:border-slate-800 flex items-center justify-end">
        <button type="submit" class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-medium transition-colors">
            Save Platform Hostnames
        </button>
    </div>
</form>
