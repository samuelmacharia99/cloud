{{-- Default nameservers handed to the reseller's domains. --}}
            <div x-show="activeTab === 'nameservers'" x-transition class="space-y-6">
                <div class="ui-card overflow-hidden">
                    <div class="bg-gradient-to-r from-cyan-600 to-cyan-700 px-6 py-4">
                        <div class="flex items-center gap-3">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 10-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                            </svg>
                            <h3 class="text-lg font-bold text-white">Default Domain Nameservers</h3>
                        </div>
                    </div>

                    <div class="p-6 space-y-6" x-data="{ usePlatformDefaults: {{ ($nameserverSettings['use_platform_defaults'] ?? true) ? 'true' : 'false' }} }">
                        <p class="text-sm text-slate-600 dark:text-slate-400">
                            These nameservers pre-fill new domain registrations and transfers at checkout. You can still override them per domain on the checkout page.
                        </p>

                        <div class="p-4 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
                            <p class="text-sm font-medium text-slate-900 dark:text-white mb-1">Platform nameservers</p>
                            <p class="text-xs font-mono text-slate-600 dark:text-slate-400">
                                {{ $platformNameservers['ns1'] }}@if(!empty($platformNameservers['ns2'])) · {{ $platformNameservers['ns2'] }}@endif
                            </p>
                        </div>

                        <form action="{{ route('reseller.settings.nameservers.update') }}" method="POST" class="space-y-6">
                            @csrf

                            <input type="hidden" name="use_platform_defaults" :value="usePlatformDefaults ? 1 : 0">

                            <div class="space-y-3">
                                <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <input type="radio" name="ns_mode" value="platform" @change="usePlatformDefaults = true" :checked="usePlatformDefaults" class="mt-1 text-cyan-600 focus:ring-cyan-500">
                                    <div>
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">Use platform default nameservers</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Recommended for domains hosted on Talksasa Cloud infrastructure.</p>
                                    </div>
                                </label>

                                <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <input type="radio" name="ns_mode" value="custom" @change="usePlatformDefaults = false" :checked="!usePlatformDefaults" class="mt-1 text-cyan-600 focus:ring-cyan-500">
                                    <div>
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">Use my custom nameservers</p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Applied to new domain orders unless changed at checkout.</p>
                                    </div>
                                </label>
                            </div>

                            <div x-show="!usePlatformDefaults" x-transition class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="ns1" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Nameserver 1</label>
                                    <input type="text" id="ns1" name="ns1" value="{{ old('ns1', $nameserverSettings['ns1'] ?? '') }}" placeholder="ns1.example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm">
                                    @error('ns1')<p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label for="ns2" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Nameserver 2</label>
                                    <input type="text" id="ns2" name="ns2" value="{{ old('ns2', $nameserverSettings['ns2'] ?? '') }}" placeholder="ns2.example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm">
                                </div>
                                <div>
                                    <label for="ns3" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Nameserver 3</label>
                                    <input type="text" id="ns3" name="ns3" value="{{ old('ns3', $nameserverSettings['ns3'] ?? '') }}" placeholder="Optional" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm">
                                </div>
                                <div>
                                    <label for="ns4" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Nameserver 4</label>
                                    <input type="text" id="ns4" name="ns4" value="{{ old('ns4', $nameserverSettings['ns4'] ?? '') }}" placeholder="Optional" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-sm">
                                </div>
                            </div>

                            <button type="submit" class="px-6 py-2 bg-cyan-600 hover:bg-cyan-700 text-white font-medium rounded-lg transition">
                                Save Nameserver Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>
