{{-- Storefront identity: logo, colours, domain and its SSL. --}}
            <div id="settings-branding-panel" x-show="activeTab === 'branding'" x-transition>
                <div class="ui-card overflow-hidden">
                    <div class="bg-gradient-to-r from-amber-600 to-amber-700 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                            </svg>
                            <h3 class="text-lg font-bold text-white">Branding Settings</h3>
                        </div>
                    </div>

                    <div class="p-6 space-y-6">
                        @if(!empty($brandingStatus))
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach($brandingStatus as $key => $item)
                                <div class="flex items-start gap-3 p-4 rounded-lg border {{ $item['ready'] ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/30' : 'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800/30' }}">
                                    <span class="text-lg">{{ $item['ready'] ? '✓' : '○' }}</span>
                                    <div>
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $item['label'] }}</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $item['hint'] }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @endif

                        @if(!empty($registrationInviteUrl))
                        <div class="p-4 rounded-lg border border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950/30">
                            <p class="text-sm font-medium text-slate-900 dark:text-white mb-2">Customer registration invite link</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">Share this link so new customers register under your brand and are linked to your account.</p>
                            <input type="text" readonly value="{{ $registrationInviteUrl }}" class="w-full px-3 py-2 text-xs font-mono border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-700 dark:text-slate-300">
                        </div>
                        @endif

                        <!-- Company Name Form -->
                        <form action="{{ route('reseller.settings.branding.update') }}" method="POST" class="space-y-6">
                            @csrf

                            <!-- Company Name -->
                            <div>
                                <label for="company_name" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Company Name</label>
                                <input type="text" id="company_name" name="company_name"
                                    value="{{ old('company_name', $brandingSettings['company_name'] ?? '') }}"
                                    placeholder="e.g., Acme Hosting" required
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">
                                @error('company_name')
                                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Tagline -->
                            <div>
                                <label for="tagline" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Tagline</label>
                                <input type="text" id="tagline" name="tagline"
                                    value="{{ old('tagline', $brandingSettings['tagline'] ?? '') }}"
                                    placeholder="e.g., Reliable hosting for your business"
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">
                            </div>

                            <!-- Primary Color -->
                            <div>
                                <label for="primary_color" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Primary Color</label>
                                <input type="color" id="primary_color" name="primary_color"
                                    value="{{ old('primary_color', $brandingSettings['primary_color'] ?? '#7c3aed') }}"
                                    class="h-10 w-20 border border-slate-300 dark:border-slate-600 rounded-lg cursor-pointer">
                            </div>

                            <!-- Support Email -->
                            <div>
                                <label for="support_email" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Support Email</label>
                                <input type="email" id="support_email" name="support_email"
                                    value="{{ old('support_email', $brandingSettings['support_email'] ?? '') }}"
                                    placeholder="support@yourcompany.com"
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">
                            </div>

                            <!-- Support Phone -->
                            <div>
                                <label for="support_phone" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Support Phone</label>
                                <input type="text" id="support_phone" name="support_phone"
                                    value="{{ old('support_phone', $brandingSettings['support_phone'] ?? '') }}"
                                    placeholder="+254..."
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">
                            </div>

                            <!-- Footer Text -->
                            <div>
                                <label for="footer_text" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Footer Text</label>
                                <textarea id="footer_text" name="footer_text" rows="2"
                                    placeholder="Shown in emails and customer portal footer"
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">{{ old('footer_text', $brandingSettings['footer_text'] ?? '') }}</textarea>
                            </div>

                            <!-- Custom Domain -->
                            @php
                                $sslStatus = $brandingSettings['ssl'] ?? [];
                                $savedCustomDomain = $brandingSettings['custom_domain'] ?? null;
                            @endphp
                            <div>
                                <label for="custom_domain" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Custom Domain</label>
                                <input type="text" id="custom_domain" name="custom_domain"
                                    value="{{ old('custom_domain', $savedCustomDomain ?? '') }}"
                                    placeholder="e.g., billing.acme.com"
                                    class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 dark:focus:ring-amber-400 text-slate-900 dark:text-white text-sm">
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Your customers can access your portal via your custom domain. Point a CNAME or A record to this server, save, then use Check DNS. HTTPS is installed on the server separately (command line).</p>
                                @error('custom_domain')
                                    <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                @enderror

                                @if(!empty($savedCustomDomain))
                                <div class="mt-4 rounded-lg border border-slate-200 dark:border-slate-700 p-4 space-y-4" x-data="sslChecker()">
                                    <div>
                                        <h4 class="text-sm font-semibold text-slate-900 dark:text-white">DNS for <span class="font-mono text-amber-700 dark:text-amber-300">{{ $savedCustomDomain }}</span></h4>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Save branding first if you changed the domain above. HTTPS is installed on the server with <code class="text-xs">scripts/reseller-ssl/provision.sh</code> (not from this page).</p>
                                    </div>

                                    <div class="bg-slate-50 dark:bg-slate-800/30 p-4 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <button type="button" @click="checkDns()" :disabled="checking"
                                            class="px-4 py-2 bg-slate-600 hover:bg-slate-700 disabled:bg-slate-500 text-white text-sm font-medium rounded-lg transition">
                                            <span x-show="!checking">Check DNS</span>
                                            <span x-show="checking">Checking...</span>
                                        </button>

                                        <div x-show="dnsChecked && dnsResult" class="mt-4 space-y-2">
                                            <div class="text-sm">
                                                <p class="text-slate-600 dark:text-slate-400">Server IP: <span class="font-mono text-slate-900 dark:text-white">{{ substr(gethostbyname(parse_url(config('app.url'), PHP_URL_HOST)), 0, 50) }}</span></p>
                                                <p class="text-slate-600 dark:text-slate-400" x-show="dnsResult?.domain_ip">
                                                    Domain IP: <span class="font-mono text-slate-900 dark:text-white" x-text="dnsResult?.domain_ip ?? '—'"></span>
                                                </p>
                                            </div>
                                            <p class="text-sm text-red-600 dark:text-red-400" x-show="dnsResult && dnsResult.success === false" x-text="dnsResult?.message ?? 'DNS check failed.'"></p>
                                            <p class="text-sm text-red-600 dark:text-red-400" x-show="dnsResult && dnsResult.success !== false && !dnsResult.match">
                                                ✗ {{ $savedCustomDomain }} is not pointing to this server
                                            </p>
                                            <p class="text-sm text-emerald-600 dark:text-emerald-400" x-show="dnsResult && dnsResult.match">
                                                ✓ DNS is correctly configured
                                            </p>
                                        </div>
                                        <p x-show="dnsChecked && !dnsResult" class="mt-4 text-sm text-red-600 dark:text-red-400">DNS check failed. Please try again.</p>
                                    </div>

                                    @if($sslStatus['status'] === 'active')
                                        <div class="bg-emerald-50 dark:bg-emerald-950/30 p-3 rounded-lg border border-emerald-200 dark:border-emerald-800">
                                            <p class="text-sm font-medium text-emerald-900 dark:text-emerald-300">SSL certificate active on server</p>
                                            @if(!empty($sslStatus['expires_at']))
                                                <p class="text-xs text-emerald-700 dark:text-emerald-400 mt-1">
                                                    Expires: {{ \Carbon\Carbon::parse($sslStatus['expires_at'])->format('M d, Y') }}
                                                </p>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                @endif
                            </div>

                            @php
                                $landingEnabled = old('landing_enabled', $brandingSettings['landing_enabled'] ?? false);
                                $landingTemplate = old('landing_template', $brandingSettings['landing_template'] ?? 'legacy');
                                $landingShowDomains = old('landing_show_domains', $brandingSettings['landing_show_domains'] ?? true);
                                $landingShowHosting = old('landing_show_hosting', $brandingSettings['landing_show_hosting'] ?? true);
                                $landingTemplates = $landingTemplates ?? [];
                                $portalPreviewUrl = filled($brandingSettings['custom_domain'] ?? null)
                                    ? 'https://'.$brandingSettings['custom_domain']
                                    : null;
                            @endphp
                            <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-5 space-y-5 bg-slate-50/80 dark:bg-slate-800/20">
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Customer landing page</h4>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                        Show a ready-made storefront on your custom domain with domain search, TLD prices, and hosting plans.
                                        No coding or API setup required. Requires a saved custom domain.
                                    </p>
                                </div>

                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input type="hidden" name="landing_enabled" value="0">
                                    <input type="checkbox" name="landing_enabled" value="1"
                                        @checked(filter_var($landingEnabled, FILTER_VALIDATE_BOOLEAN))
                                        class="mt-1 rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                                    <span>
                                        <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">Enable landing page on custom domain</span>
                                        <span class="block text-xs text-slate-500 dark:text-slate-400 mt-0.5">When off, visitors to your domain go straight to login.</span>
                                    </span>
                                </label>

                                <div>
                                    <p class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Template</p>
                                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                                        @foreach ($landingTemplates as $key => $meta)
                                            @php $available = (bool) ($meta['available'] ?? false); @endphp
                                            <label class="relative rounded-xl border p-4 transition {{ $available ? 'cursor-pointer bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-700 has-[:checked]:border-amber-500 has-[:checked]:ring-2 has-[:checked]:ring-amber-500/30' : 'opacity-60 cursor-not-allowed bg-slate-100 dark:bg-slate-900/40 border-slate-200 dark:border-slate-700' }}">
                                                <input type="radio" name="landing_template" value="{{ $key }}"
                                                    class="sr-only"
                                                    @checked($landingTemplate === $key)
                                                    @disabled(! $available)>
                                                <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ $meta['label'] }}</span>
                                                <span class="block text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $meta['description'] }}</span>
                                                @unless ($available)
                                                    <span class="inline-block mt-2 text-[10px] font-bold uppercase tracking-wide text-slate-500">Coming soon</span>
                                                @endunless
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="grid sm:grid-cols-2 gap-4">
                                    <div>
                                        <label for="landing_hero_headline" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Hero headline (optional)</label>
                                        <input type="text" id="landing_hero_headline" name="landing_hero_headline"
                                            value="{{ old('landing_hero_headline', $brandingSettings['landing_hero_headline'] ?? '') }}"
                                            placeholder="Defaults to your company name"
                                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 text-slate-900 dark:text-white text-sm">
                                    </div>
                                    <div>
                                        <label for="landing_hero_subtext" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Hero subtext (optional)</label>
                                        <input type="text" id="landing_hero_subtext" name="landing_hero_subtext"
                                            value="{{ old('landing_hero_subtext', $brandingSettings['landing_hero_subtext'] ?? '') }}"
                                            placeholder="Defaults to your tagline"
                                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 text-slate-900 dark:text-white text-sm">
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-6">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="hidden" name="landing_show_domains" value="0">
                                        <input type="checkbox" name="landing_show_domains" value="1"
                                            @checked(filter_var($landingShowDomains, FILTER_VALIDATE_BOOLEAN))
                                            class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                                        <span class="text-sm text-slate-700 dark:text-slate-300">Show domain search &amp; prices</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="hidden" name="landing_show_hosting" value="0">
                                        <input type="checkbox" name="landing_show_hosting" value="1"
                                            @checked(filter_var($landingShowHosting, FILTER_VALIDATE_BOOLEAN))
                                            class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                                        <span class="text-sm text-slate-700 dark:text-slate-300">Show hosting plans</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="hidden" name="landing_show_trust" value="0">
                                        <input type="checkbox" name="landing_show_trust" value="1"
                                            @checked(filter_var(old('landing_show_trust', $brandingSettings['landing_show_trust'] ?? true), FILTER_VALIDATE_BOOLEAN))
                                            class="rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                                        <span class="text-sm text-slate-700 dark:text-slate-300">Show trust strip</span>
                                    </label>
                                </div>

                                <div class="grid sm:grid-cols-2 gap-4 pt-2 border-t border-slate-200 dark:border-slate-700">
                                    <div>
                                        <label for="landing_meta_title" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SEO title (optional)</label>
                                        <input type="text" id="landing_meta_title" name="landing_meta_title"
                                            value="{{ old('landing_meta_title', $brandingSettings['landing_meta_title'] ?? '') }}"
                                            maxlength="70"
                                            placeholder="Defaults to company name"
                                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 text-slate-900 dark:text-white text-sm">
                                    </div>
                                    <div>
                                        <label for="website_url" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Marketing website URL</label>
                                        <input type="text" id="website_url" name="website_url"
                                            value="{{ old('website_url', $brandingSettings['website_url'] ?? '') }}"
                                            placeholder="https://www.example.com"
                                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 text-slate-900 dark:text-white text-sm">
                                        <p class="mt-1 text-xs text-slate-500">Shown as “Our website” in the landing footer (e.g. your WordPress site).</p>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label for="landing_meta_description" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">SEO description (optional)</label>
                                        <input type="text" id="landing_meta_description" name="landing_meta_description"
                                            value="{{ old('landing_meta_description', $brandingSettings['landing_meta_description'] ?? '') }}"
                                            maxlength="160"
                                            placeholder="Defaults to tagline"
                                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 text-slate-900 dark:text-white text-sm">
                                    </div>
                                    <div>
                                        <label for="landing_ga_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Google Analytics ID</label>
                                        <input type="text" id="landing_ga_id" name="landing_ga_id"
                                            value="{{ old('landing_ga_id', $brandingSettings['landing_ga_id'] ?? '') }}"
                                            placeholder="G-XXXXXXXX"
                                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 text-slate-900 dark:text-white text-sm font-mono">
                                    </div>
                                    <div>
                                        <label for="landing_gtm_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Google Tag Manager ID</label>
                                        <input type="text" id="landing_gtm_id" name="landing_gtm_id"
                                            value="{{ old('landing_gtm_id', $brandingSettings['landing_gtm_id'] ?? '') }}"
                                            placeholder="GTM-XXXXXXX"
                                            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 text-slate-900 dark:text-white text-sm font-mono">
                                        <p class="mt-1 text-xs text-slate-500">If set, GTM is used instead of the GA snippet.</p>
                                    </div>
                                </div>

                                <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-4 space-y-3">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900 dark:text-white">Promo code (optional)</p>
                                        <p class="text-xs text-slate-500 mt-0.5">One simple code customers can enter on the cart. Not a full coupon system.</p>
                                    </div>
                                    <div class="grid sm:grid-cols-3 gap-3">
                                        <div>
                                            <label for="promo_code" class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Code</label>
                                            <input type="text" id="promo_code" name="promo_code"
                                                value="{{ old('promo_code', $brandingSettings['promo_code'] ?? '') }}"
                                                placeholder="SAVE10"
                                                class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm font-mono uppercase text-slate-900 dark:text-white">
                                        </div>
                                        <div>
                                            <label for="promo_type" class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Type</label>
                                            <select id="promo_type" name="promo_type"
                                                class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm text-slate-900 dark:text-white">
                                                <option value="percent" @selected(old('promo_type', $brandingSettings['promo_type'] ?? 'percent') === 'percent')>Percent %</option>
                                                <option value="fixed" @selected(old('promo_type', $brandingSettings['promo_type'] ?? '') === 'fixed')>Fixed KES</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label for="promo_value" class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Value</label>
                                            <input type="number" step="0.01" min="0" id="promo_value" name="promo_value"
                                                value="{{ old('promo_value', $brandingSettings['promo_value'] ?? '') }}"
                                                placeholder="10"
                                                class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm text-slate-900 dark:text-white">
                                        </div>
                                    </div>
                                </div>

                                @if ($portalPreviewUrl)
                                    <p class="text-xs text-slate-600 dark:text-slate-400">
                                        Preview after saving:
                                        <a href="{{ $portalPreviewUrl }}" target="_blank" rel="noopener" class="text-amber-700 dark:text-amber-300 font-medium hover:underline">{{ $portalPreviewUrl }}</a>
                                    </p>
                                @else
                                    <p class="text-xs text-amber-700 dark:text-amber-300">Save a custom domain above before enabling the landing page.</p>
                                @endif
                            </div>

                            @php
                                $publicApiEnabled = old('public_api_enabled', $publicApiSettings['enabled'] ?? false);
                                $publicApiOrigins = old(
                                    'public_api_allowed_origins',
                                    implode("\n", $publicApiSettings['allowed_origins'] ?? []),
                                );
                            @endphp
                            <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-5 space-y-4 bg-slate-50/80 dark:bg-slate-800/20">
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Website sales API</h4>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Let visitors on your own site search domains, browse your services, and check out on your branding domain. Requires a saved custom domain.</p>
                                </div>

                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input type="hidden" name="public_api_enabled" value="0">
                                    <input type="checkbox" name="public_api_enabled" value="1"
                                        @checked($publicApiEnabled)
                                        class="mt-1 rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                                    <span>
                                        <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">Enable public website API</span>
                                        <span class="block text-xs text-slate-500 dark:text-slate-400 mt-0.5">Opt-in. When enabled, JSON endpoints are available on your custom domain only.</span>
                                    </span>
                                </label>

                                <div>
                                    <label for="public_api_allowed_origins" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Allowed website origins (optional)</label>
                                    <textarea id="public_api_allowed_origins" name="public_api_allowed_origins" rows="3"
                                        placeholder="https://www.yourcompany.com&#10;https://yourcompany.com"
                                        class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-amber-500 text-slate-900 dark:text-white text-sm font-mono">{{ $publicApiOrigins }}</textarea>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">One origin per line. Required only if your marketing site is on a different domain than your portal API. Same-domain embeds do not need CORS.</p>
                                </div>

                                @if($publicApiBaseUrl)
                                    <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-950/20 p-4 space-y-2">
                                        <p class="text-xs font-medium text-amber-900 dark:text-amber-200">API base URL</p>
                                        <code class="block text-xs break-all text-amber-800 dark:text-amber-300">{{ $publicApiBaseUrl }}</code>
                                        <p class="text-xs text-slate-600 dark:text-slate-400">Endpoints and token management live on the <a href="{{ route('reseller.developers.index') }}" class="text-purple-600 dark:text-purple-400 font-medium hover:underline">Developers</a> page.</p>
                                    </div>
                                @else
                                    <p class="text-xs text-amber-700 dark:text-amber-300">Save a custom domain above to see your API base URL.</p>
                                @endif
                            </div>

                            <div class="flex flex-wrap gap-3">
                                <button type="submit" class="px-6 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition">
                                    Save Branding Settings
                                </button>
                            </div>
                        </form>

                        <!-- Logo Upload -->
                        <div class="border-t border-slate-200 dark:border-slate-700 pt-6">
                            <h4 class="font-medium text-slate-900 dark:text-white mb-4">Logo</h4>
                            <div class="space-y-4">
                                @php
                                    $storedLogoUrl = $brandingSettings['logo_url'] ?? null;
                                    $storedLogoPath = $brandingSettings['logo_path'] ?? null;
                                    $resellerLogoUrl = branding_asset_url($storedLogoUrl);
                                    if (! $resellerLogoUrl && $storedLogoPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($storedLogoPath)) {
                                        $resellerLogoUrl = '/storage/'.$storedLogoPath;
                                    }
                                    $hasCustomLogo = ! empty($storedLogoUrl) || ! empty($storedLogoPath);
                                    $platformLogoUrl = branding_asset_url_or_fallback(null, 'logo');
                                @endphp
                                @if($resellerLogoUrl)
                                    <div class="flex items-center justify-between bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <div class="flex items-center gap-3">
                                            <img src="{{ $resellerLogoUrl }}" alt="Logo" class="h-12 w-auto max-w-[120px] object-contain">
                                            <div>
                                                <p class="text-sm font-medium text-slate-900 dark:text-white">Your Logo</p>
                                                <p class="text-xs text-slate-500 dark:text-slate-400">Recommended size: 500x150px</p>
                                            </div>
                                        </div>
                                        <form action="{{ route('reseller.settings.branding.delete') }}" method="POST" class="flex">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="type" value="logo">
                                            <button type="submit" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-lg transition">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                @elseif($hasCustomLogo)
                                    <div class="flex items-center justify-between bg-amber-50 dark:bg-amber-950/30 p-4 rounded-lg border border-amber-200 dark:border-amber-800">
                                        <p class="text-sm text-amber-800 dark:text-amber-300">Your logo file is missing from storage. Upload a new file or remove the broken entry.</p>
                                        <form action="{{ route('reseller.settings.branding.delete') }}" method="POST" class="flex shrink-0">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="type" value="logo">
                                            <button type="submit" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-lg transition">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                @elseif($platformLogoUrl)
                                    <div class="flex items-center gap-3 bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <img src="{{ $platformLogoUrl }}" alt="Platform logo" class="h-12 w-auto max-w-[120px] object-contain opacity-60">
                                        <div>
                                            <p class="text-sm font-medium text-slate-900 dark:text-white">Platform default</p>
                                            <p class="text-xs text-slate-500 dark:text-slate-400">Customers see this logo until you upload your own.</p>
                                        </div>
                                    </div>
                                @endif
                                <form action="{{ route('reseller.settings.branding.upload') }}" method="POST" enctype="multipart/form-data" class="flex flex-col gap-2">
                                    @csrf
                                    <input type="hidden" name="type" value="logo">
                                    <div class="relative">
                                        <label for="logo_file" class="flex items-center justify-center w-full px-4 py-3 border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-lg cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                            <div class="text-center">
                                                <svg class="mx-auto h-8 w-8 text-slate-400 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                                </svg>
                                                <p class="text-sm text-slate-600 dark:text-slate-400">Click to upload or drag and drop</p>
                                                <p class="text-xs text-slate-500 dark:text-slate-500">PNG, JPG, GIF or WebP (max 2MB)</p>
                                            </div>
                                            <input id="logo_file" name="file" type="file" class="hidden" accept="image/*" required>
                                        </label>
                                    </div>
                                    <button type="submit" class="px-6 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition">
                                        {{ $hasCustomLogo ? 'Replace Logo' : 'Upload Logo' }}
                                    </button>
                                    @error('file')
                                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </form>
                            </div>
                        </div>

                        <!-- Favicon Upload -->
                        <div class="border-t border-slate-200 dark:border-slate-700 pt-6">
                            <h4 class="font-medium text-slate-900 dark:text-white mb-4">Favicon</h4>
                            <div class="space-y-4">
                                @php
                                    $storedFaviconUrl = $brandingSettings['favicon_url'] ?? null;
                                    $storedFaviconPath = $brandingSettings['favicon_path'] ?? null;
                                    $resellerFaviconUrl = branding_asset_url($storedFaviconUrl);
                                    if (! $resellerFaviconUrl && $storedFaviconPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($storedFaviconPath)) {
                                        $resellerFaviconUrl = '/storage/'.$storedFaviconPath;
                                    }
                                    $hasCustomFavicon = ! empty($storedFaviconUrl) || ! empty($storedFaviconPath);
                                    $platformFaviconUrl = branding_asset_url_or_fallback(null, 'favicon');
                                @endphp
                                @if($resellerFaviconUrl)
                                    <div class="flex items-center justify-between bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <div class="flex items-center gap-3">
                                            <img src="{{ $resellerFaviconUrl }}" alt="Favicon" class="h-8 w-8 object-contain">
                                            <div>
                                                <p class="text-sm font-medium text-slate-900 dark:text-white">Your Favicon</p>
                                                <p class="text-xs text-slate-500 dark:text-slate-400">Recommended size: 32x32px or 64x64px</p>
                                            </div>
                                        </div>
                                        <form action="{{ route('reseller.settings.branding.delete') }}" method="POST" class="flex">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="type" value="favicon">
                                            <button type="submit" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-lg transition">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                @elseif($hasCustomFavicon)
                                    <div class="flex items-center justify-between bg-amber-50 dark:bg-amber-950/30 p-4 rounded-lg border border-amber-200 dark:border-amber-800">
                                        <p class="text-sm text-amber-800 dark:text-amber-300">Your favicon file is missing from storage. Upload a new file or remove the broken entry.</p>
                                        <form action="{{ route('reseller.settings.branding.delete') }}" method="POST" class="flex shrink-0">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="type" value="favicon">
                                            <button type="submit" class="px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 text-sm font-medium rounded-lg transition">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                @elseif($platformFaviconUrl)
                                    <div class="flex items-center gap-3 bg-slate-50 dark:bg-slate-800/50 p-4 rounded-lg border border-slate-200 dark:border-slate-700">
                                        <img src="{{ $platformFaviconUrl }}" alt="Platform favicon" class="h-8 w-8 object-contain opacity-60">
                                        <div>
                                            <p class="text-sm font-medium text-slate-900 dark:text-white">Platform default</p>
                                            <p class="text-xs text-slate-500 dark:text-slate-400">Customers see this favicon until you upload your own.</p>
                                        </div>
                                    </div>
                                @endif
                                <form action="{{ route('reseller.settings.branding.upload') }}" method="POST" enctype="multipart/form-data" class="flex flex-col gap-2">
                                    @csrf
                                    <input type="hidden" name="type" value="favicon">
                                    <div class="relative">
                                        <label for="favicon_file" class="flex items-center justify-center w-full px-4 py-3 border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-lg cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/50 transition">
                                            <div class="text-center">
                                                <svg class="mx-auto h-8 w-8 text-slate-400 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                                </svg>
                                                <p class="text-sm text-slate-600 dark:text-slate-400">Click to upload or drag and drop</p>
                                                <p class="text-xs text-slate-500 dark:text-slate-500">PNG, ICO or GIF (max 2MB)</p>
                                            </div>
                                            <input id="favicon_file" name="file" type="file" class="hidden" accept="image/*" required>
                                        </label>
                                    </div>
                                    <button type="submit" class="px-6 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition">
                                        {{ $hasCustomFavicon ? 'Replace Favicon' : 'Upload Favicon' }}
                                    </button>
                                    @error('file')
                                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </form>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
